import { Injectable, Logger, OnModuleInit } from '@nestjs/common';
import { TestSessionChange } from 'testcenter-common/interfaces/test-session-change.interface';
import { Monitor } from '../monitor/monitor.interface';
import { WebsocketGateway } from '../common/websocket.gateway';
import { RedisService } from '../redis/redis.service';
import {
  CHANNEL, KEY, SessionChangeMessage
} from '../redis/redis.constants';

@Injectable()
export class TestSessionService implements OnModuleInit {
  constructor(
    private readonly websocketGateway: WebsocketGateway,
    private readonly redisService: RedisService
  ) {
    this.websocketGateway.getDisconnectionObservable().subscribe((disconnected: string) => {
      this.removeMonitor(disconnected).catch(e => this.logger.error(e.message));
    });
  }

  private readonly logger = new Logger(TestSessionService.name);

  async onModuleInit(): Promise<void> {
    // Every pod receives every session-change fan-out and sends only to the sockets it holds.
    await this.redisService.subscribe(CHANNEL.sessionChange, (msg: SessionChangeMessage) => {
      const localTokens = this.websocketGateway.filterLocalTokens(msg.tokens);
      if (localTokens.length) {
        this.websocketGateway.broadcastToRegistered(localTokens, 'test-sessions', msg.sessions);
      }
    });
    await this.redisService.subscribe(CHANNEL.systemClean, () => {
      this.websocketGateway.disconnectAll();
    });
  }

  async applySessionChange(sessionChange: TestSessionChange): Promise<void> {
    const stored = await this.addSessionChange(sessionChange);
    if (stored) {
      await this.broadcastTestSessionsToGroupMonitors(sessionChange.groupName);
    }
  }

  async applySessionChanges(sessionChanges: TestSessionChange[]): Promise<void> {
    const groupsToBroadcast = new Set<string>();
    for (const sessionChange of sessionChanges) {
      // eslint-disable-next-line no-await-in-loop
      const stored = await this.addSessionChange(sessionChange);
      if (stored) {
        groupsToBroadcast.add(sessionChange.groupName);
      }
    }
    await Promise.all([...groupsToBroadcast].map(group => this.broadcastTestSessionsToGroupMonitors(group)));
  }

  /** Atomically merge the change into Redis. Returns false if nobody monitors the group (skipped). */
  private async addSessionChange(sessionChange: TestSessionChange): Promise<boolean> {
    const group = sessionChange.groupName;
    const monitorTokens = await this.redisService.smembers(KEY.monitorGroups(group));
    if (monitorTokens.length === 0) {
      // skipping, group is not monitored
      return false;
    }
    await this.redisService.mergeSessionHash(group, sessionChange.testId, sessionChange);
    await this.redisService.sadd(KEY.activeGroups, group);
    return true;
  }

  private async broadcastTestSessionsToGroupMonitors(groupName: string): Promise<void> {
    const monitorTokens = await this.redisService.smembers(KEY.monitorGroups(groupName));
    if (monitorTokens.length === 0) {
      return;
    }
    const sessions = await this.redisService.hgetall<TestSessionChange>(KEY.testSessions(groupName));

    // Lazy cleanup: drop monitors with no live socket anywhere in the cluster, broadcast to the rest.
    const { alive, dead } = await this.redisService.partitionByAlive(monitorTokens);
    await Promise.all(dead.map(token => this.removeMonitor(token)));

    await this.redisService.publish(CHANNEL.sessionChange, {
      groupName, sessions, tokens: alive
    } as SessionChangeMessage);
  }

  async addMonitor(monitor: Monitor): Promise<void> {
    await Promise.all(monitor.groups.map(async (group: string) => {
      await this.redisService.sadd(KEY.monitorGroups(group), monitor.token);
    }));
    await this.redisService.hset(KEY.monitors, monitor.token, monitor);
  }

  async removeMonitor(monitorToken: string): Promise<void> {
    const monitor = await this.redisService.hget<Monitor>(KEY.monitors, monitorToken);
    if (monitor) {
      this.logger.log(`remove monitor: ${monitorToken}`);
      await Promise.all(monitor.groups.map(async (group: string) => {
        await this.redisService.srem(KEY.monitorGroups(group), monitorToken);
        const remaining = await this.redisService.smembers(KEY.monitorGroups(group));
        if (remaining.length === 0) {
          await this.redisService.del(KEY.testSessions(group));
          await this.redisService.srem(KEY.activeGroups, group);
        }
      }));
      await this.redisService.hdel(KEY.monitors, monitorToken);
    }

    // Close the socket if it happens to live on this pod (no-op otherwise).
    this.websocketGateway.disconnectClient(monitorToken);
  }

  async getMonitors(): Promise<Monitor[]> {
    return this.redisService.hgetall<Monitor>(KEY.monitors);
  }

  async getTestSessions(): Promise<TestSessionChange[]> {
    const groups = await this.redisService.smembers(KEY.activeGroups);
    const perGroup = await Promise.all(
      groups.map(group => this.redisService.hgetall<TestSessionChange>(KEY.testSessions(group)))
    );
    return perGroup.flat();
  }

  getClientTokens(): string[] {
    return this.websocketGateway.getClientTokens();
  }

  async clean(): Promise<void> {
    const groups = new Set<string>(await this.redisService.smembers(KEY.activeGroups));
    // also collect groups of monitors that have no session state yet
    const monitors = await this.redisService.hgetall<Monitor>(KEY.monitors);
    monitors.forEach(monitor => monitor.groups.forEach(group => groups.add(group)));

    await Promise.all([...groups].flatMap(group => [
      this.redisService.del(KEY.testSessions(group)),
      this.redisService.del(KEY.monitorGroups(group))
    ]));
    await this.redisService.del(KEY.activeGroups);
    await this.redisService.del(KEY.monitors);
  }
}
