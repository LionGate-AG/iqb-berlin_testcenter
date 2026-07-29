/* eslint-disable @typescript-eslint/dot-notation */
import { Test, TestingModule } from '@nestjs/testing';
import { WebSocket } from 'ws';
import { IncomingMessage } from 'http';
import { TestSessionChange } from 'testcenter-common/interfaces/test-session-change.interface';
import { TestSessionService } from './test-session.service';
import { Monitor } from '../monitor/monitor.interface';
import { WebsocketGateway } from '../common/websocket.gateway';
import { RedisService } from '../redis/redis.service';
import { FakeRedisService } from '../redis/redis.fake';
import { KEY } from '../redis/redis.constants';

let testSessionService : TestSessionService;
let websocketGateway : WebsocketGateway;
let redis : FakeRedisService;

const mockMonitor1 : Monitor = { token: 'monitorToken1', groups: ['Gruppe1', 'TestakerGroup1', 'Gruppe2'] };
const mockMonitor2 : Monitor = { token: 'monitorToken2', groups: ['Gruppe1', 'TestakerGroup1', 'Gruppe2'] };
const mockMonitor3 : Monitor = { token: 'monitorToken3', groups: ['Gruppe3', 'Gruppe5'] };

const makeWs = (): WebSocket => ({
  send: jest.fn(), close: jest.fn(), on: jest.fn(), terminate: jest.fn(), readyState: 1
} as unknown as WebSocket);

// Connect a live socket for a token on this pod (also marks it alive in Redis).
const connect = (token: string): WebSocket => {
  const ws = makeWs();
  websocketGateway.handleConnection(ws, { url: `x/ws?token=${token}` } as IncomingMessage);
  return ws;
};

const build = async (): Promise<void> => {
  redis = new FakeRedisService();
  const module: TestingModule = await Test.createTestingModule({
    providers: [TestSessionService, WebsocketGateway, RedisService]
  }).overrideProvider(RedisService).useValue(redis).compile();

  testSessionService = module.get<TestSessionService>(TestSessionService);
  websocketGateway = module.get<WebsocketGateway>(WebsocketGateway);
  await testSessionService.onModuleInit(); // wire pub/sub handlers
};

describe('TestSessionService: add and remove monitors', () => {
  beforeEach(build);

  it('should be defined', () => {
    expect(testSessionService).toBeDefined();
  });

  it('should add monitors (registration written to Redis)', async () => {
    await testSessionService.addMonitor(mockMonitor1);
    expect(await redis.smembers(KEY.monitorGroups('Gruppe1'))).toContain('monitorToken1');
    expect(await redis.smembers(KEY.monitorGroups('TestakerGroup1'))).toContain('monitorToken1');
    expect(await redis.smembers(KEY.monitorGroups('Gruppe2'))).toContain('monitorToken1');
    expect(await redis.hget<Monitor>(KEY.monitors, 'monitorToken1')).toStrictEqual(mockMonitor1);
  });

  it('should remove monitor (resulting in empty monitor list)', async () => {
    const spyDisconnectClient = jest.spyOn(websocketGateway, 'disconnectClient');

    await testSessionService.addMonitor(mockMonitor1);
    await testSessionService.removeMonitor(mockMonitor1.token);

    expect(await redis.smembers(KEY.monitorGroups('Gruppe1'))).toStrictEqual([]);
    expect(await redis.smembers(KEY.monitorGroups('TestakerGroup1'))).toStrictEqual([]);
    expect(await redis.hget(KEY.monitors, 'monitorToken1')).toBeNull();
    expect(spyDisconnectClient).toHaveBeenCalledWith('monitorToken1');
  });

  it('should remove monitor (other monitor remains)', async () => {
    await testSessionService.addMonitor(mockMonitor1);
    await testSessionService.addMonitor(mockMonitor2);
    await testSessionService.removeMonitor(mockMonitor1.token);

    expect(await redis.smembers(KEY.monitorGroups('Gruppe1'))).toStrictEqual(['monitorToken2']);
    expect(await redis.hget(KEY.monitors, 'monitorToken1')).toBeNull();
    expect(await redis.hget<Monitor>(KEY.monitors, 'monitorToken2')).toStrictEqual(mockMonitor2);
  });

  it('should call getClientTokens', () => {
    const spyGetClientTokens = jest.spyOn(websocketGateway, 'getClientTokens');
    testSessionService.getClientTokens();
    expect(spyGetClientTokens).toHaveBeenCalled();
  });
});

describe('testSessionService: get and clear all monitors', () => {
  beforeEach(async () => {
    await build();
    await testSessionService.addMonitor(mockMonitor1);
    await testSessionService.addMonitor(mockMonitor2);
    await testSessionService.addMonitor(mockMonitor3);
  });

  it('should return all monitors', async () => {
    expect(await testSessionService.getMonitors()).toEqual(
      expect.arrayContaining([mockMonitor1, mockMonitor2, mockMonitor3])
    );
  });

  it('should clear all monitors and testSessions', async () => {
    await testSessionService.clean();
    expect(await testSessionService.getMonitors()).toStrictEqual([]);
    expect(await testSessionService.getTestSessions()).toStrictEqual([]);
  });
});

describe('testSessionService sessionChanges', () => {
  const mockSessionChange1 : TestSessionChange = {
    personId: 357,
    groupName: 'TestakerGroup1',
    testId: 381,
    personLabel: 'user2',
    groupLabel: 'TestakerGroup1',
    mode: 'run-hot-return',
    testState: {
      CONTROLLER: 'TERMINATED',
      CURRENT_UNIT_ID: 'Endunit',
      TESTLETS_CLEARED_CODE: '["Examples"], ["ExamplesOl"]',
      FOCUS: 'HAS',
      status: 'locked',
      old: 'old'
    },
    bookletName: 'BOOKLET1',
    unitName: 'Endunit',
    unitState: {
      PLAYER: 'RUNNING', RESPONSE_PROGRESS: 'none', PRESENTATION_PROGRESS: 'complete', OLD_STATE: 'old state'
    },
    timestamp: 1630051624
  };
  const mockSessionChange1Updated : TestSessionChange = {
    personId: 357,
    groupName: 'TestakerGroup1',
    testId: 381,
    personLabel: 'user2',
    groupLabel: 'TestakerGroup1',
    mode: 'run-hot-return',
    testState: {
      CONTROLLER: 'RUNNING',
      CURRENT_UNIT_ID: 'Endunit',
      TESTLETS_CLEARED_CODE: '["Examples"], ["Examples2"]',
      FOCUS: 'HAS',
      status: 'not_locked',
      new: 'new'
    },
    bookletName: 'BOOKLET2',
    unitName: 'Endunit',
    unitState: {
      PLAYER: 'RUNNING', RESPONSE_PROGRESS: 'none', PRESENTATION_PROGRESS: 'complete', NEW_STATE: 'new state'
    },
    timestamp: 1630051874
  };
  const mockSessionChangeNoMonitor : TestSessionChange = {
    personId: 9,
    groupName: 'Gruppe6',
    testId: 10,
    personLabel: 'valid Personlabel',
    groupLabel: 'Gruppe1',
    mode: 'run-hot-return',
    testState: {
      CONTROLLER: 'TERMINATED', CURRENT_UNIT_ID: 'FB_unit3', FOCUS: 'HAS', status: 'locked'
    },
    bookletName: 'BOOKLET_VERSION1',
    unitName: 'FB_unit3',
    unitState: { PLAYER: 'RUNNING', PRESENTATION_PROGRESS: 'complete', RESPONSE_PROGRESS: 'some' },
    timestamp: 1630051624
  };
  const mockSessionChange2 : TestSessionChange = {
    personId: 6,
    groupName: 'Gruppe2',
    testId: 7,
    personLabel: 'V212',
    groupLabel: 'Gruppe2',
    mode: 'run-hot-return',
    testState: {
      CONTROLLER: 'TERMINATED', CURRENT_UNIT_ID: 'FB_unit3', FOCUS: 'HAS', status: 'locked'
    },
    bookletName: 'BOOKLET_VERSION2',
    unitName: 'FB_unit3',
    unitState: { PLAYER: 'RUNNING', PRESENTATION_PROGRESS: 'complete', RESPONSE_PROGRESS: 'some' },
    timestamp: 1630051624
  };
  const mockSessionChange3 : TestSessionChange = {
    personId: 7,
    groupName: 'Gruppe3',
    testId: 8,
    personLabel: 'V315',
    groupLabel: 'Gruppe3',
    mode: 'run-hot-return',
    testState: {
      CONTROLLER: 'TERMINATED', CURRENT_UNIT_ID: 'FB_unit3', FOCUS: 'HAS', status: 'locked'
    },
    bookletName: 'BOOKLET_VERSION3',
    unitName: 'FB_unit3',
    unitState: { PLAYER: 'RUNNING', PRESENTATION_PROGRESS: 'complete', RESPONSE_PROGRESS: 'some' },
    timestamp: 1630051624
  };

  beforeEach(async () => {
    await build();
    await testSessionService.addMonitor(mockMonitor1);
    await testSessionService.addMonitor(mockMonitor3);
    // keep monitors alive so the lazy-eviction pass does not drop them mid-test
    connect(mockMonitor1.token);
    connect(mockMonitor3.token);
  });

  it('should skip changes for unmonitored groups', async () => {
    await testSessionService.applySessionChange(mockSessionChangeNoMonitor);
    expect(await testSessionService.getTestSessions()).toStrictEqual([]);
  });

  it('should create a session entry and deliver to the monitor socket', async () => {
    const ws = connect('liveMonitorSocket'); // unrelated extra socket, should not receive
    expect(ws).toBeDefined();
    const monitorWs = (websocketGateway['clients'] as Map<string, WebSocket>).get(mockMonitor1.token)!;
    const spySend = jest.spyOn(monitorWs, 'send');

    await testSessionService.applySessionChange(mockSessionChange1);

    expect(await testSessionService.getTestSessions()).toStrictEqual([mockSessionChange1]);
    expect(spySend).toHaveBeenCalledWith(JSON.stringify({ event: 'test-sessions', data: [mockSessionChange1] }));
  });

  it('should update a session entry (same unit name)', async () => {
    const expectedSession : TestSessionChange = {
      personId: 357,
      groupName: 'TestakerGroup1',
      testId: 381,
      personLabel: 'user2',
      groupLabel: 'TestakerGroup1',
      mode: 'run-hot-return',
      testState: {
        CONTROLLER: 'RUNNING',
        CURRENT_UNIT_ID: 'Endunit',
        TESTLETS_CLEARED_CODE: '["Examples"], ["Examples2"]',
        FOCUS: 'HAS',
        status: 'not_locked',
        old: 'old',
        new: 'new'
      },
      bookletName: 'BOOKLET2',
      unitName: 'Endunit',
      unitState: {
        PLAYER: 'RUNNING',
        RESPONSE_PROGRESS: 'none',
        PRESENTATION_PROGRESS: 'complete',
        OLD_STATE: 'old state',
        NEW_STATE: 'new state'
      },
      timestamp: 1630051874
    };

    await testSessionService.applySessionChange(mockSessionChange1);
    await testSessionService.applySessionChange(mockSessionChange1Updated);
    expect(await testSessionService.getTestSessions()).toStrictEqual([expectedSession]);
  });

  it('should update a session entry (different unit name resets unitState)', async () => {
    const updated = {
      ...mockSessionChange1Updated,
      unitName: 'Testunit',
      unitState: { PLAYER: 'RUNNING', PRESENTATION_PROGRESS: 'complete' }
    };
    const expectedSession : TestSessionChange = {
      personId: 357,
      groupName: 'TestakerGroup1',
      testId: 381,
      personLabel: 'user2',
      groupLabel: 'TestakerGroup1',
      mode: 'run-hot-return',
      testState: {
        CONTROLLER: 'RUNNING',
        CURRENT_UNIT_ID: 'Endunit',
        TESTLETS_CLEARED_CODE: '["Examples"], ["Examples2"]',
        FOCUS: 'HAS',
        status: 'not_locked',
        old: 'old',
        new: 'new'
      },
      bookletName: 'BOOKLET2',
      unitName: 'Testunit',
      unitState: { PLAYER: 'RUNNING', PRESENTATION_PROGRESS: 'complete' },
      timestamp: 1630051874
    };

    await testSessionService.applySessionChange(mockSessionChange1);
    await testSessionService.applySessionChange(updated);
    expect(await testSessionService.getTestSessions()).toStrictEqual([expectedSession]);
  });

  it('should return an array of all sessionChanges', async () => {
    await testSessionService.applySessionChange(mockSessionChange1);
    await testSessionService.applySessionChange(mockSessionChange2);
    await testSessionService.applySessionChange(mockSessionChange3);
    expect(await testSessionService.getTestSessions()).toEqual(
      expect.arrayContaining([mockSessionChange1, mockSessionChange2, mockSessionChange3])
    );
  });

  it('should evict a monitor that has no live socket anywhere', async () => {
    // monitorToken2 registered but never connected (not alive) -> evicted on next broadcast
    await testSessionService.addMonitor(mockMonitor2);
    await testSessionService.applySessionChange(mockSessionChange1);
    // mockMonitor2 had no live socket, so it should have been removed from Redis
    expect(await redis.hget(KEY.monitors, 'monitorToken2')).toBeNull();
    // the connected monitor (mockMonitor1) survives
    expect(await redis.hget<Monitor>(KEY.monitors, 'monitorToken1')).toStrictEqual(mockMonitor1);
  });
});
