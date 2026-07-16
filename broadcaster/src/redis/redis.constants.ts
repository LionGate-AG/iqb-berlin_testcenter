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
  websocketConnections: 'websocket-connections' // LIST of connected tokens (ops/debug only)
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
