import { TestSessionChange } from 'testcenter-common/interfaces/test-session-change.interface';

/**
 * In-memory stand-in for RedisService used by unit tests. Implements the same public surface and
 * delivers published messages synchronously to subscribers, so a single instance models one pod.
 */
export class FakeRedisService {
  hashes = new Map<string, Map<string, string>>();
  sets = new Map<string, Set<string>>();
  alive = new Set<string>();
  connections: string[] = [];
  handlers = new Map<string, (payload: any) => void>();
  published: { channel: string; payload: any }[] = [];

  private hash(key: string): Map<string, string> {
    if (!this.hashes.has(key)) this.hashes.set(key, new Map());
    return this.hashes.get(key)!;
  }

  private set(key: string): Set<string> {
    if (!this.sets.has(key)) this.sets.set(key, new Set());
    return this.sets.get(key)!;
  }

  async hset(key: string, field: string, value: unknown): Promise<void> {
    this.hash(key).set(field, JSON.stringify(value));
  }

  async hget<T>(key: string, field: string): Promise<T | null> {
    const raw = this.hashes.get(key)?.get(field);
    return raw ? (JSON.parse(raw) as T) : null;
  }

  async hdel(key: string, field: string): Promise<void> {
    this.hashes.get(key)?.delete(field);
  }

  async hgetall<T>(key: string): Promise<T[]> {
    return [...(this.hashes.get(key)?.values() ?? [])].map(v => JSON.parse(v) as T);
  }

  async sadd(key: string, member: string): Promise<void> {
    this.set(key).add(member);
  }

  async srem(key: string, member: string): Promise<void> {
    this.sets.get(key)?.delete(member);
  }

  async smembers(key: string): Promise<string[]> {
    return [...(this.sets.get(key)?.values() ?? [])];
  }

  async del(key: string): Promise<void> {
    this.hashes.delete(key);
    this.sets.delete(key);
  }

  async pushConnection(token: string): Promise<void> {
    this.connections.push(token);
  }

  async removeConnection(token: string): Promise<void> {
    this.connections = this.connections.filter(t => t !== token);
  }

  async setClientAlive(token: string): Promise<void> {
    this.alive.add(token);
  }

  async deleteClientAlive(token: string): Promise<void> {
    this.alive.delete(token);
  }

  async partitionByAlive(tokens: string[]): Promise<{ alive: string[]; dead: string[] }> {
    const alive: string[] = [];
    const dead: string[] = [];
    tokens.forEach(t => (this.alive.has(t) ? alive : dead).push(t));
    return { alive, dead };
  }

  async publish(channel: string, payload: unknown): Promise<void> {
    this.published.push({ channel, payload });
    const handler = this.handlers.get(channel);
    if (handler) handler(JSON.parse(JSON.stringify(payload)));
  }

  async subscribe(channel: string, handler: (payload: any) => void): Promise<void> {
    this.handlers.set(channel, handler);
  }

  async mergeSessionHash(
    group: string,
    testId: number | string,
    incoming: TestSessionChange
  ): Promise<TestSessionChange> {
    const key = `testSessions:${group}`;
    const existingRaw = this.hashes.get(key)?.get(String(testId));
    let merged: TestSessionChange;
    if (existingRaw) {
      const existing = JSON.parse(existingRaw) as TestSessionChange;
      if (incoming.unitName && incoming.unitName !== existing.unitName) {
        existing.unitState = {};
      }
      const unitState = { ...existing.unitState, ...incoming.unitState };
      const testState = { ...existing.testState, ...incoming.testState };
      merged = {
        ...existing, ...incoming, unitState, testState
      };
    } else {
      merged = incoming;
    }
    this.hash(key).set(String(testId), JSON.stringify(merged));
    return merged;
  }
}
