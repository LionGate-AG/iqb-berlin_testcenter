import {
  Injectable, Logger, OnModuleDestroy, OnModuleInit
} from '@nestjs/common';
import Redis from 'ioredis';
import { TestSessionChange } from 'testcenter-common/interfaces/test-session-change.interface';
import { CLIENT_ALIVE_TTL_SECONDS, KEY } from './redis.constants';

type ChannelHandler = (payload: any) => void;

/**
 * Atomic deep-merge of an incoming TestSessionChange into the testSessions:<group> hash field.
 * Mirrors mergeSessionChanges() in the former in-memory implementation, but runs server-side so
 * concurrent updates to the same testId (possibly from different pods) cannot interleave or lose data.
 * KEYS[1] = hash key (testSessions:<group>), ARGV[1] = field (testId), ARGV[2] = incoming JSON.
 * Returns the merged JSON that was stored.
 */
const MERGE_SESSION_LUA = `
local existingRaw = redis.call('HGET', KEYS[1], ARGV[1])
local incoming = cjson.decode(ARGV[2])
local merged
if existingRaw then
  local existing = cjson.decode(existingRaw)
  if incoming.unitName ~= nil and incoming.unitName ~= existing.unitName then
    existing.unitState = {}
  end
  local unitState = existing.unitState or {}
  if incoming.unitState ~= nil then
    for k, v in pairs(incoming.unitState) do unitState[k] = v end
  end
  local testState = existing.testState or {}
  if incoming.testState ~= nil then
    for k, v in pairs(incoming.testState) do testState[k] = v end
  end
  merged = existing
  for k, v in pairs(incoming) do merged[k] = v end
  merged.unitState = unitState
  merged.testState = testState
else
  merged = incoming
end
local encoded = cjson.encode(merged)
redis.call('HSET', KEYS[1], ARGV[1], encoded)
return encoded
`;

@Injectable()
export class RedisService implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(RedisService.name);

  // Two connections: a normal one for commands + publishing, and a subscriber-only one
  // (a connection in subscriber mode cannot issue regular commands).
  private readonly pub: Redis;
  private readonly sub: Redis;

  private readonly handlers = new Map<string, ChannelHandler>();

  constructor() {
    const options = {
      host: process.env.REDIS_HOST || 'localhost',
      port: parseInt(process.env.REDIS_PORT || '6379', 10),
      ...(process.env.REDIS_PASSWORD ? { password: process.env.REDIS_PASSWORD } : {}),
      // keep retrying; the pod should not crash just because Redis blips
      retryStrategy: (times: number): number => Math.min(times * 200, 2000),
      maxRetriesPerRequest: null as null
    };
    this.pub = new Redis(options);
    this.sub = new Redis(options);
    this.pub.defineCommand('mergeSession', { numberOfKeys: 1, lua: MERGE_SESSION_LUA });
  }

  onModuleInit(): void {
    this.pub.on('error', e => this.logger.error(`redis(pub) error: ${e.message}`));
    this.sub.on('error', e => this.logger.error(`redis(sub) error: ${e.message}`));

    this.sub.on('message', (channel: string, message: string) => {
      const handler = this.handlers.get(channel);
      if (!handler) {
        return;
      }
      try {
        handler(JSON.parse(message));
      } catch (e) {
        this.logger.error(`failed to handle message on ${channel}: ${(e as Error).message}`);
      }
    });
  }

  async onModuleDestroy(): Promise<void> {
    await Promise.allSettled([this.pub.quit(), this.sub.quit()]);
  }

  // ---- pub/sub ----
  async publish(channel: string, payload: unknown): Promise<void> {
    await this.pub.publish(channel, JSON.stringify(payload));
  }

  async subscribe(channel: string, handler: ChannelHandler): Promise<void> {
    this.handlers.set(channel, handler);
    await this.sub.subscribe(channel);
  }

  // ---- hashes ----
  async hset(key: string, field: string, value: unknown): Promise<void> {
    await this.pub.hset(key, field, JSON.stringify(value));
  }

  async hget<T>(key: string, field: string): Promise<T | null> {
    const raw = await this.pub.hget(key, field);
    return raw ? (JSON.parse(raw) as T) : null;
  }

  async hdel(key: string, field: string): Promise<void> {
    await this.pub.hdel(key, field);
  }

  async hgetall<T>(key: string): Promise<T[]> {
    const all = await this.pub.hgetall(key);
    return Object.values(all).map(v => JSON.parse(v) as T);
  }

  // ---- sets ----
  async sadd(key: string, member: string): Promise<void> {
    await this.pub.sadd(key, member);
  }

  async srem(key: string, member: string): Promise<void> {
    await this.pub.srem(key, member);
  }

  async smembers(key: string): Promise<string[]> {
    return this.pub.smembers(key);
  }

  async del(key: string): Promise<void> {
    await this.pub.del(key);
  }

  // ---- connection tracking (ops/debug only) ----
  async pushConnection(token: string): Promise<void> {
    await this.pub.rpush(KEY.websocketConnections, token);
  }

  async removeConnection(token: string): Promise<void> {
    await this.pub.lrem(KEY.websocketConnections, 0, token);
  }

  // ---- liveness ----
  async setClientAlive(token: string): Promise<void> {
    await this.pub.set(KEY.clientAlive(token), '1', 'EX', CLIENT_ALIVE_TTL_SECONDS);
  }

  async deleteClientAlive(token: string): Promise<void> {
    await this.pub.del(KEY.clientAlive(token));
  }

  /**
   * Split a list of tokens into the ones that still have a live connection somewhere in the cluster
   * (client-alive:<token> exists) and the ones that don't. Pipelined EXISTS, one round trip.
   */
  async partitionByAlive(tokens: string[]): Promise<{ alive: string[]; dead: string[] }> {
    if (tokens.length === 0) {
      return { alive: [], dead: [] };
    }
    const pipeline = this.pub.pipeline();
    tokens.forEach(t => pipeline.exists(KEY.clientAlive(t)));
    const results = await pipeline.exec();
    const alive: string[] = [];
    const dead: string[] = [];
    tokens.forEach((token, i) => {
      const exists = results && results[i] && results[i][1] === 1;
      (exists ? alive : dead).push(token);
    });
    return { alive, dead };
  }

  // ---- atomic session merge ----
  async mergeSessionHash(
    group: string,
    testId: number | string,
    incoming: TestSessionChange
  ): Promise<TestSessionChange> {
    // @ts-expect-error - defineCommand adds this method at runtime
    const merged: string = await this.pub.mergeSession(
      KEY.testSessions(group),
      String(testId),
      JSON.stringify(incoming)
    );
    return JSON.parse(merged) as TestSessionChange;
  }
}
