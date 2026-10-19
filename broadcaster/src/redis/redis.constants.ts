import { TestSessionChange } from 'testcenter-common/interfaces/test-session-change.interface';
import { Command } from '../command/command.interface';

// ---- Redis keys (described in docs/site/pages/scaling-broadcaster.md) ----
export const KEY = {
  testees: 'testees', // HASH token -> Testee JSON
  testeeTestId: (testId: number | string): string => `testee-testid:${testId}`, // SET of testee tokens
  monitors: 'monitors', // HASH token -> Monitor JSON
  monitorGroups: (group: string): string => `monitor-groups:${group}`, // SET of monitor tokens
  testSessions: (group: string): string => `testSessions:${group}`, // HASH testId -> TestSessionChange JSON
  activeGroups: 'activeGroups', // SET of group names with session state
  clientAlive: (token: string): string => `client-alive:${token}`, // STRING "1" EX
  // ZSET token -> registration time (ms). A token the backend registered (testee or monitor) that has
  // not been used by a WebSocket yet. Taken (ZREM) on connect; expired by the heartbeat after
  // TOKEN_CONNECT_TIMEOUT (see WebsocketGateway.verifyRegistration / expireUnconnectedTokens).
  wsRegistrations: 'ws-registrations',

  // SET of connected tokens (ops/debug only; nothing in the app reads it). A SET, so that adding and
  // removing a token is O(1) regardless of the number of connections.
  connectedTokens: 'connected-tokens'
};

export const CLIENT_ALIVE_TTL_SECONDS = 90;

// ---- Pub/sub channels ----
export const CHANNEL = {
  sessionChange: 'broadcaster:session-change',
  command: 'broadcaster:command',
  systemClean: 'broadcaster:system-clean'
} as const;

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

export type SystemCleanMessage = Record<string, never>;

export interface ChannelPayloads {
  [CHANNEL.sessionChange]: SessionChangeMessage;
  [CHANNEL.command]: CommandMessage;
  [CHANNEL.systemClean]: SystemCleanMessage;
}

export type Channel = keyof ChannelPayloads;
