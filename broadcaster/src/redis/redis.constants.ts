import { TestSessionChange } from 'testcenter-common/interfaces/test-session-change.interface';
import { Command } from '../command/command.interface';

// ---- Redis keys (see data model) ----
export const KEY = {
  testees: 'testees', // HASH token -> Testee JSON
  testeeTestId: (testId: number | string): string => `testee-testid:${testId}`, // SET of testee tokens
  monitors: 'monitors', // HASH token -> Monitor JSON
  monitorGroups: (group: string): string => `monitor-groups:${group}`, // SET of monitor tokens
  testSessions: (group: string): string => `testSessions:${group}`, // HASH testId -> TestSessionChange JSON
  activeGroups: 'activeGroups', // SET of group names with session state
  clientAlive: (token: string): string => `client-alive:${token}`, // STRING "1" EX
  // SET of connected tokens (ops/debug only; nothing in the app reads it).
  // Was a LIST under the key `websocket-connections`, which made removal an O(N)
  // `LREM` over every connected token -- at ~30k concurrent clients that measured
  // ~48ms per disconnect, and since Redis is single-threaded each one stalled the
  // WHOLE server. A burst of disconnects (e.g. one heartbeat sweep terminating
  // stale sockets) serialized enough of them to make `redis-cli ping` exceed the
  // 3s probe timeout, so this single shared Redis -- and therefore every
  // broadcaster pod depending on it -- failed its health check at once. SADD/SREM
  // are O(1), so add/remove no longer scale with connection count.
  // Deliberately a NEW key name: switching the type in place would make every
  // SADD fail with WRONGTYPE for as long as the old LIST key still existed.
  connectedTokens: 'connected-tokens'
};

export const CLIENT_ALIVE_TTL_SECONDS = 90;

// ---- Pub/sub channels ----
export const CHANNEL = {
  sessionChange: 'broadcaster:session-change',
  command: 'broadcaster:command',
  systemClean: 'broadcaster:system-clean'
};

// ---- Pub/sub payloads ----
export interface SessionChangeMessage {
  groupName: string;
  sessions: TestSessionChange[];
  tokens: string[];
}

export interface CommandMessage {
  command: Command;
  tokens: string[];
}

// eslint-disable-next-line @typescript-eslint/no-empty-interface
export interface SystemCleanMessage {}
