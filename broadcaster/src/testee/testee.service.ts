import { Injectable, Logger, OnModuleInit } from '@nestjs/common';
import { HttpService } from '@nestjs/axios';
import { firstValueFrom } from 'rxjs';
import { Testee } from './testee.interface';
import { WebsocketGateway } from '../common/websocket.gateway';
import { Command } from '../command/command.interface';
import { RedisService } from '../redis/redis.service';
import { CHANNEL, CommandMessage, KEY } from '../redis/redis.constants';

const sleep = (ms: number): Promise<void> => new Promise(resolve => { setTimeout(resolve, ms); });

@Injectable()
export class TesteeService implements OnModuleInit {
  private static readonly NOTIFY_MAX_RETRIES = 3;

  constructor(
    private readonly websocketGateway: WebsocketGateway,
    private readonly redisService: RedisService,
    private readonly http: HttpService
  ) {
    this.websocketGateway.getDisconnectionObservable().subscribe((disconnected: string) => {
      // Read+notify the disconnect URI BEFORE the registration is deleted, then remove.
      this.notifyDisconnection(disconnected)
        .catch(() => {})
        .then(() => this.removeTestee(disconnected))
        .catch(e => this.logger.error(e.message));
    });
    // Upstream 12ff306ec: a registration whose socket never connected is dropped without reporting a
    // connection loss to the backend (there never was a connection).
    this.websocketGateway.getTokenExpiryObservable().subscribe((expired: string) => {
      this.removeTestee(expired).catch(e => this.logger.error(e.message));
    });
  }

  private readonly logger = new Logger(TesteeService.name);

  async onModuleInit(): Promise<void> {
    await this.redisService.subscribe(CHANNEL.command, (msg: CommandMessage) => {
      const localTokens = this.websocketGateway.filterLocalTokens(msg.tokens);
      if (localTokens.length) {
        this.websocketGateway.broadcastToRegistered(localTokens, 'commands', [msg.command]);
      }
    });
  }

  async addTestee(testee: Testee): Promise<void> {
    await this.redisService.sadd(KEY.testeeTestId(testee.testId), testee.token);
    await this.redisService.hset(KEY.testees, testee.token, testee);
    // Last: a socket may connect as soon as its token is allowed, and must then find the testee in place.
    await this.websocketGateway.allowToken(testee.token);
  }

  async removeTestee(testeeToken: string): Promise<void> {
    const testee = await this.redisService.hget<Testee>(KEY.testees, testeeToken);
    if (testee) {
      this.logger.log(`remove testee: ${testeeToken}`);
      await this.redisService.srem(KEY.testeeTestId(testee.testId), testeeToken);
      await this.redisService.hdel(KEY.testees, testeeToken);
    }

    this.websocketGateway.disconnectClient(testeeToken);
  }

  async getTestees(): Promise<Testee[]> {
    return this.redisService.hgetall<Testee>(KEY.testees);
  }

  async notifyDisconnection(testeeToken: string): Promise<void> {
    const testee = await this.redisService.hget<Testee>(KEY.testees, testeeToken);
    if (!testee || !testee.disconnectNotificationUri) {
      return;
    }
    // Upstream 285ebe8ac: the heartbeat may detect a dead socket only after the test has reconnected with
    // another token -- possibly on another pod, so liveness is read from Redis, not from the local map.
    if (await this.isTestConnectedElsewhere(testee)) {
      this.logger.log(`test of ${testeeToken} is still connected, not sending connection-lost signal`);
      return;
    }

    const uri = new URL(testee.disconnectNotificationUri);
    const logUri = testee.disconnectNotificationUri.replace(uri.search, '');
    const testMode = uri.searchParams.get('testMode');
    const config = testMode ? { headers: { testMode } } : {};

    for (let attempt = 0; attempt < TesteeService.NOTIFY_MAX_RETRIES; attempt++) {
      try {
        // eslint-disable-next-line no-await-in-loop
        await firstValueFrom(this.http.post(testee.disconnectNotificationUri, {}, config));
        this.logger.log(`sent connection-lost signal to ${logUri}`);
        return;
      } catch (error) {
        const isLast = attempt === TesteeService.NOTIFY_MAX_RETRIES - 1;
        this.logger.warn(
          `could not send connection-lost signal to ${logUri} (attempt ${attempt + 1}): ${(error as Error).message}`
        );
        if (isLast) {
          return;
        }
        // eslint-disable-next-line no-await-in-loop
        await sleep(2 ** attempt * 200); // exponential backoff: 200ms, 400ms, ...
      }
    }
  }

  private async isTestConnectedElsewhere(testee: Testee): Promise<boolean> {
    const otherTokens = (await this.redisService.smembers(KEY.testeeTestId(testee.testId)))
      .filter(token => token !== testee.token);
    if (otherTokens.length === 0) {
      return false;
    }
    const { alive } = await this.redisService.partitionByAlive(otherTokens);
    return alive.length > 0;
  }

  async broadcastCommandToTestees(command: Command, testIds: number[]): Promise<void> {
    // union of testee tokens across all addressed testIds (deduplicated)
    const tokenLists = await Promise.all(testIds.map(testId => this.redisService.smembers(KEY.testeeTestId(testId))));
    const tokens = [...new Set(tokenLists.flat())];
    if (tokens.length === 0) {
      return;
    }

    const { alive, dead } = await this.redisService.partitionByAlive(tokens);
    await Promise.all(dead.map(token => this.removeTestee(token)));

    await this.redisService.publish(CHANNEL.command, { command, tokens: alive } as CommandMessage);
  }

  async clean(): Promise<void> {
    const testees = await this.redisService.hgetall<Testee>(KEY.testees);
    const testIds = new Set<number>(testees.map(testee => testee.testId));
    await Promise.all([...testIds].map(testId => this.redisService.del(KEY.testeeTestId(testId))));
    await this.redisService.del(KEY.testees);
  }
}
