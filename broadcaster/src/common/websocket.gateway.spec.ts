/* eslint-disable @typescript-eslint/dot-notation */
import { Test, TestingModule } from '@nestjs/testing';
import { WebSocket } from 'ws';
import { IncomingMessage } from 'http';
import { isObservable } from 'rxjs';
import { WebsocketGateway } from './websocket.gateway';
import { BroadcastingEvent } from './interfaces';
import { RedisService } from '../redis/redis.service';
import { FakeRedisService } from '../redis/redis.fake';

let websocketGateway : WebsocketGateway;

describe('websocketGateway handle connection and disconnection (single client)', () => {
  const client = {
    key: 'ClientKey',
    token: 'tokenstring',
    close: jest.fn(),
    send: jest.fn(),
    on: jest.fn(),
    terminate: jest.fn(),
    readyState: 1
  } as unknown as WebSocket;
  const client2 = {
    key: 'ClientKey2',
    token: 'tokenstring2',
    close: jest.fn(),
    send: jest.fn(),
    on: jest.fn(),
    terminate: jest.fn(),
    readyState: 1
  } as unknown as WebSocket;
  const client3 = {
    key: 'ClientKey3',
    token: 'tokenstring2',
    close: jest.fn(),
    send: jest.fn(),
    on: jest.fn(),
    terminate: jest.fn(),
    readyState: 1
  } as unknown as WebSocket;
  const incomingMessage = { url: 'www.test.de/ws?token=clientToken' } as IncomingMessage;
  const incomingMessage2 = { url: 'www.test.de/ws?token=clientToken2' } as IncomingMessage;
  const incomingMessage3 = { url: 'www.test.de/ws?token=clientToken3' } as IncomingMessage;
  const expectedTokens = ['clientToken', 'clientToken2', 'clientToken3'];

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [WebsocketGateway, RedisService]
    }).overrideProvider(RedisService).useValue(new FakeRedisService()).compile();

    websocketGateway = module.get<WebsocketGateway>(WebsocketGateway);
    await Promise.all(expectedTokens.map(token => websocketGateway.allowToken(token)));
  });

  it('should be defined', () => {
    expect(websocketGateway).toBeDefined();
  });

  it('it should handle a connection', () => {
    const spyLogger = jest.spyOn(websocketGateway['logger'], 'log');
    expect(websocketGateway.handleConnection(client, incomingMessage)).toBeUndefined();
    expect(websocketGateway['clients'].get('clientToken')).toStrictEqual(client);
    expect(websocketGateway['clientsCount$'].value).toEqual(1);
    expect(spyLogger).toHaveBeenCalled();
  });

  it('should handle more than one connection', () => {
    const spyLogger = jest.spyOn(websocketGateway['logger'], 'log');
    expect(websocketGateway.handleConnection(client as WebSocket, incomingMessage)).toBeUndefined();
    expect(websocketGateway['clients'].get('clientToken')).toStrictEqual(client);
    expect(websocketGateway['clientsCount$'].value).toEqual(1);
    expect(websocketGateway.handleConnection(client2, incomingMessage2)).toBeUndefined();
    expect(websocketGateway['clients'].get('clientToken2')).toStrictEqual(client2);
    expect(websocketGateway['clientsCount$'].value).toEqual(2);
    expect(spyLogger).toHaveBeenCalled();
  });

  it('should reject a connection with a token that was not registered', async () => {
    const unknownClient = { close: jest.fn(), on: jest.fn() } as unknown as WebSocket;
    const unknownMessage = { url: 'www.test.de/ws?token=unknownToken' } as IncomingMessage;
    websocketGateway.handleConnection(unknownClient, unknownMessage);
    await new Promise(resolve => { setImmediate(resolve); }); // the registration check is one Redis round trip
    expect(unknownClient.close).toHaveBeenCalledWith(1008, 'Invalid token');
    expect(websocketGateway['clients'].size).toEqual(0);
  });

  it('should reject a second connection with the same token', () => {
    const duplicateClient = { close: jest.fn(), on: jest.fn() } as unknown as WebSocket;
    websocketGateway.handleConnection(client, incomingMessage);
    websocketGateway.handleConnection(duplicateClient, incomingMessage);
    expect(duplicateClient.close).toHaveBeenCalledWith(1008, 'Invalid token');
    expect(websocketGateway['clients'].get('clientToken')).toStrictEqual(client);
    expect(websocketGateway['clients'].size).toEqual(1);
  });

  it('should reject a token after its client was disconnected', async () => {
    const returningClient = { close: jest.fn(), on: jest.fn() } as unknown as WebSocket;
    websocketGateway.handleConnection(client, incomingMessage);
    websocketGateway.disconnectClient('clientToken');
    websocketGateway.handleConnection(returningClient, incomingMessage);
    await new Promise(resolve => { setImmediate(resolve); }); // the registration check is one Redis round trip
    expect(returningClient.close).toHaveBeenCalledWith(1008, 'Invalid token');
    expect(websocketGateway['clients'].size).toEqual(0);
  });

  it('should handle a disconnect (empty client list)', () => {
    websocketGateway.handleConnection(client, incomingMessage);
    expect(websocketGateway.handleDisconnect(client)).toBeUndefined();
    expect(websocketGateway['clients'].get('clientToken')).toBeUndefined();
    expect(websocketGateway['clientsCount$'].value).toEqual(0);
  });

  it('should handle a disconnect (non-empty client list)', () => {
    const spyLogger = jest.spyOn(websocketGateway['logger'], 'log');
    const spyClientLost = jest.spyOn(websocketGateway['clientLost$'], 'next');
    websocketGateway.handleConnection(client, incomingMessage);
    websocketGateway.handleConnection(client2, incomingMessage2);
    expect(websocketGateway.handleDisconnect(client)).toBeUndefined();
    expect(websocketGateway['clients'].get('clientToken')).toBeUndefined();
    expect(websocketGateway['clients'].get('clientToken2')).toStrictEqual(client2);
    expect(websocketGateway['clientsCount$'].value).toEqual(1);
    expect(spyLogger).toHaveBeenCalled();
    expect(spyClientLost).toHaveBeenCalledWith('clientToken');
  });

  it('should disconnect a client (only one client)', () => {
    const monitorToken : string = 'clientToken';
    websocketGateway.handleConnection(client, incomingMessage);
    expect(websocketGateway.disconnectClient(monitorToken)).toBeUndefined();
    expect(websocketGateway['clients'].get('clientToken')).toBeUndefined();
    expect(websocketGateway['clients'].size).toEqual(0);
  });

  it('should disconnect a client (more than one client)', () => {
    const monitorToken : string = 'clientToken';
    websocketGateway.handleConnection(client, incomingMessage);
    websocketGateway.handleConnection(client2, incomingMessage2);
    expect(websocketGateway.disconnectClient(monitorToken)).toBeUndefined();
    expect(websocketGateway['clients'].get('clientToken')).toBeUndefined();
    expect(websocketGateway['clients'].get('clientToken2')).toStrictEqual(client2);
    expect(websocketGateway['clients'].size).toEqual(1);
  });

  it('should disconnect all Clients', () => {
    websocketGateway.handleConnection(client, incomingMessage);
    websocketGateway.handleConnection(client2, incomingMessage2);
    websocketGateway.disconnectAll();
    expect(websocketGateway['clients'].size).toEqual(0);
  });

  it('should return disconnections as observable', () => {
    websocketGateway.handleConnection(client, incomingMessage);
    websocketGateway.handleDisconnect(client);
    expect(isObservable(websocketGateway.getDisconnectionObservable())).toEqual(true);
  });

  it('should return all clientTokens', () => {
    websocketGateway.handleConnection(client, incomingMessage);
    websocketGateway.handleConnection(client2, incomingMessage2);
    websocketGateway.handleConnection(client3, incomingMessage3);
    expect(websocketGateway.getClientTokens()).toStrictEqual(expectedTokens);
  });

  it('should filter to only locally-held tokens', () => {
    websocketGateway.handleConnection(client, incomingMessage);
    websocketGateway.handleConnection(client2, incomingMessage2);
    expect(websocketGateway.filterLocalTokens(['clientToken', 'clientToken2', 'somewhere-else']))
      .toStrictEqual(['clientToken', 'clientToken2']);
  });

  it('should broadcast to all registered', () => {
    websocketGateway.handleConnection(client, incomingMessage);
    websocketGateway.handleConnection(client2, incomingMessage2);
    const spyLogger = jest.spyOn(websocketGateway['logger'], 'log');
    const spySend = jest.spyOn(client, 'send');
    const spySend2 = jest.spyOn(client2, 'send');
    const event = 'test-sessions' as BroadcastingEvent;
    const message = {};
    const tokens = websocketGateway.getClientTokens();
    websocketGateway.broadcastToRegistered(tokens, event, message);
    expect(spyLogger).toHaveBeenCalledTimes(2);
    expect(spySend).toHaveBeenCalled();
    expect(spySend2).toHaveBeenCalled();
  });

  it('should return subscribe:client.count', () => {
    websocketGateway.handleConnection(client, incomingMessage);
    websocketGateway.handleConnection(client2, incomingMessage2);
    expect(isObservable(websocketGateway.subscribeClientCount(1))).toStrictEqual(true);
  });
});

describe('websocketGateway heartbeat sweep', () => {
  const makeClient = (): WebSocket => ({
    close: jest.fn(), send: jest.fn(), on: jest.fn(), terminate: jest.fn(), ping: jest.fn(), readyState: 1
  } as unknown as WebSocket);

  // Registers the tokens first: only registered tokens may connect (see verifyRegistration).
  const connectClients = async (tokens: string[]): Promise<WebSocket[]> => {
    await Promise.all(tokens.map(token => websocketGateway.allowToken(token)));
    const clients = tokens.map(makeClient);
    clients.forEach((c, i) => websocketGateway.handleConnection(c, { url: `x/ws?token=${tokens[i]}` } as IncomingMessage));
    return clients;
  };

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [WebsocketGateway, RedisService]
    }).overrideProvider(RedisService).useValue(new FakeRedisService()).compile();

    websocketGateway = module.get<WebsocketGateway>(WebsocketGateway);
  });

  it('should not terminate freshly-connected clients on the first sweep', async () => {
    const [client] = await connectClients(['t1']);
    const spyWarn = jest.spyOn(websocketGateway['logger'], 'warn');

    await websocketGateway['runHeartbeatSweep']();

    expect(client.terminate).not.toHaveBeenCalled();
    expect(client.ping).toHaveBeenCalled();
    expect(websocketGateway['clients'].has('t1')).toBe(true);
    expect(spyWarn).not.toHaveBeenCalled();
  });

  it('should terminate clients that missed a pong and log a single summary line (not one per client)', async () => {
    const tokens = ['t1', 't2', 't3'];
    const clients = await connectClients(tokens);

    await websocketGateway['runHeartbeatSweep'](); // tick 1: ping everyone, clear their "alive" flag
    const spyWarn = jest.spyOn(websocketGateway['logger'], 'warn');
    await websocketGateway['runHeartbeatSweep'](); // tick 2: nobody ponged in between -> all stale

    clients.forEach(c => expect(c.terminate).toHaveBeenCalledTimes(1));
    tokens.forEach(t => expect(websocketGateway['clients'].has(t)).toBe(false));
    expect(websocketGateway['clientsCount$'].value).toBe(0);
    expect(spyWarn).toHaveBeenCalledTimes(1);
    expect(spyWarn).toHaveBeenCalledWith(expect.stringContaining('3'));
  });

  it('should keep a client that ponged between ticks alive', async () => {
    const [client] = await connectClients(['t1']);

    await websocketGateway['runHeartbeatSweep'](); // tick 1
    (websocketGateway['aliveClients'] as WeakSet<WebSocket>).add(client); // simulate a pong
    await websocketGateway['runHeartbeatSweep'](); // tick 2

    expect(client.terminate).not.toHaveBeenCalled();
    expect(websocketGateway['clients'].has('t1')).toBe(true);
  });

  it('should report a client dropped by the heartbeat as lost', async () => {
    const [client] = await connectClients(['deadToken']);
    const spyClientLost = jest.spyOn(websocketGateway['clientLost$'], 'next');

    await websocketGateway['runHeartbeatSweep'](); // tick 1: ping, no pong follows
    await websocketGateway['runHeartbeatSweep'](); // tick 2: stale -> terminated

    expect(client.terminate).toHaveBeenCalled();
    expect(spyClientLost).toHaveBeenCalledWith('deadToken');
    expect(websocketGateway['clients'].size).toEqual(0);
  });

  it('should process every client correctly across multiple batches', async () => {
    const GatewayCtor = WebsocketGateway as unknown as { HEARTBEAT_BATCH_SIZE: number };
    const originalBatchSize = GatewayCtor.HEARTBEAT_BATCH_SIZE;
    GatewayCtor.HEARTBEAT_BATCH_SIZE = 2; // force several yields for a small client count

    try {
      const tokens = Array.from({ length: 5 }, (_, i) => `t${i}`);
      const clients = await connectClients(tokens);

      await websocketGateway['runHeartbeatSweep'](); // tick 1: ping everyone
      await websocketGateway['runHeartbeatSweep'](); // tick 2: all stale, spanning 3 batches of size 2

      clients.forEach(c => expect(c.terminate).toHaveBeenCalledTimes(1));
      expect(websocketGateway['clients'].size).toBe(0);
    } finally {
      GatewayCtor.HEARTBEAT_BATCH_SIZE = originalBatchSize;
    }
  });

  it('should skip a heartbeat tick if the previous sweep is still running', async () => {
    const spySweep = jest.spyOn(websocketGateway as any, 'runHeartbeatSweep');
    const spyWarn = jest.spyOn(websocketGateway['logger'], 'warn');

    websocketGateway['onHeartbeatTick']();
    websocketGateway['onHeartbeatTick']();

    expect(spySweep).toHaveBeenCalledTimes(1);
    expect(spyWarn).toHaveBeenCalledWith(expect.stringContaining('skipping'));

    // let the in-flight sweep settle so it doesn't leak into later tests
    await new Promise(resolve => { setImmediate(resolve); });
  });

  it('should allow a new sweep once the previous one has completed', async () => {
    const spySweep = jest.spyOn(websocketGateway as any, 'runHeartbeatSweep');

    websocketGateway['onHeartbeatTick']();
    await new Promise(resolve => { setImmediate(resolve); });
    websocketGateway['onHeartbeatTick']();
    await new Promise(resolve => { setImmediate(resolve); });

    expect(spySweep).toHaveBeenCalledTimes(2);
  });
});

const createGateway = async (redis: FakeRedisService): Promise<WebsocketGateway> => {
  const module: TestingModule = await Test.createTestingModule({
    providers: [WebsocketGateway, RedisService]
  }).overrideProvider(RedisService).useValue(redis).compile();
  return module.get<WebsocketGateway>(WebsocketGateway);
};

const makeSocket = (): WebSocket => ({
  close: jest.fn(), send: jest.fn(), on: jest.fn(), terminate: jest.fn(), ping: jest.fn(), readyState: 1
} as unknown as WebSocket);

const connectMessage = (token: string): IncomingMessage => ({ url: `x/ws?token=${token}` } as IncomingMessage);

// lets the registration check (one Redis round trip in production) finish
const settle = (): Promise<void> => new Promise(resolve => { setImmediate(resolve); });

describe('websocketGateway token registration across pods', () => {
  let redis: FakeRedisService;
  let podA: WebsocketGateway;
  let podB: WebsocketGateway;

  beforeEach(async () => {
    redis = new FakeRedisService(); // one Redis, shared by two broadcaster pods
    podA = await createGateway(redis);
    podB = await createGateway(redis);
  });

  it('should accept a connection on a pod that did not receive the registration', async () => {
    await podA.allowToken('crossPodToken');
    const socket = makeSocket();

    podB.handleConnection(socket, connectMessage('crossPodToken'));
    await settle();

    expect(socket.close).not.toHaveBeenCalled();
    expect(podB['clients'].has('crossPodToken')).toBe(true);
    expect(redis.alive.has('crossPodToken')).toBe(true);
  });

  it('should admit only one of two sockets that connect with one fresh token on two pods', async () => {
    await podA.allowToken('freshToken');
    const socketA = makeSocket();
    const socketB = makeSocket();

    podA.handleConnection(socketA, connectMessage('freshToken'));
    podB.handleConnection(socketB, connectMessage('freshToken'));
    await settle();

    expect(socketA.close).not.toHaveBeenCalled();
    expect(socketB.close).toHaveBeenCalledWith(1008, 'Invalid token');
    expect(podB['clients'].has('freshToken')).toBe(false);
    expect(redis.alive.has('freshToken')).toBe(true); // pod A's marker survives pod B's rejection
  });

  it('should not announce a rejected socket in Redis', async () => {
    const socket = makeSocket();

    podA.handleConnection(socket, connectMessage('madeUpToken'));
    await settle();

    expect(socket.close).toHaveBeenCalledWith(1008, 'Invalid token');
    expect(redis.alive.has('madeUpToken')).toBe(false);
    expect(redis.connections).not.toContain('madeUpToken');
  });
});

describe('websocketGateway token expiry', () => {
  let redis: FakeRedisService;
  let now: number;

  beforeEach(async () => {
    now = 1000000;
    jest.spyOn(Date, 'now').mockImplementation(() => now);
    redis = new FakeRedisService();
    websocketGateway = await createGateway(redis);
    await websocketGateway.allowToken('unusedToken');
  });

  afterEach(() => {
    jest.restoreAllMocks();
  });

  it('should expire a token that does not connect in time', async () => {
    const spyTokenExpired = jest.spyOn(websocketGateway['tokenExpired$'], 'next');

    now += 30000;
    await websocketGateway['runHeartbeatSweep']();

    expect(spyTokenExpired).toHaveBeenCalledWith('unusedToken');
    expect(redis.registrations.has('unusedToken')).toBe(false);
  });

  it('should reject a connection with an expired token', async () => {
    now += 30000;
    await websocketGateway['runHeartbeatSweep']();
    const socket = makeSocket();

    websocketGateway.handleConnection(socket, connectMessage('unusedToken'));
    await settle();

    expect(socket.close).toHaveBeenCalledWith(1008, 'Invalid token');
    expect(websocketGateway['clients'].size).toEqual(0);
  });

  it('should not expire a token that connected', async () => {
    const spyTokenExpired = jest.spyOn(websocketGateway['tokenExpired$'], 'next');
    websocketGateway.handleConnection(makeSocket(), connectMessage('unusedToken'));
    await settle();

    now += 30000;
    await websocketGateway['runHeartbeatSweep']();

    expect(spyTokenExpired).not.toHaveBeenCalled();
    expect(websocketGateway['clients'].has('unusedToken')).toBe(true);
  });

  it('should not expire a token that registered again while its socket is connected', async () => {
    websocketGateway.handleConnection(makeSocket(), connectMessage('unusedToken'));
    await settle();
    await websocketGateway.allowToken('unusedToken'); // e.g. a command poll after the socket connected
    const spyTokenExpired = jest.spyOn(websocketGateway['tokenExpired$'], 'next');

    now += 30000;
    await websocketGateway['runHeartbeatSweep']();

    expect(spyTokenExpired).not.toHaveBeenCalled();
    expect(websocketGateway['clients'].has('unusedToken')).toBe(true);
    expect(redis.registrations.has('unusedToken')).toBe(false);
  });

  it('should expire a token only once although every pod sweeps', async () => {
    const podB = await createGateway(redis);
    const spyA = jest.spyOn(websocketGateway['tokenExpired$'], 'next');
    const spyB = jest.spyOn(podB['tokenExpired$'], 'next');

    now += 30000;
    await Promise.all([websocketGateway['runHeartbeatSweep'](), podB['runHeartbeatSweep']()]);

    expect(spyA.mock.calls.length + spyB.mock.calls.length).toBe(1);
  });

  it('should not expire a token registered shortly before the heartbeat', async () => {
    const spyTokenExpired = jest.spyOn(websocketGateway['tokenExpired$'], 'next');

    now += 20000;
    await websocketGateway.allowToken('lateToken');
    now += 10000;
    await websocketGateway['runHeartbeatSweep']();

    expect(spyTokenExpired).toHaveBeenCalledWith('unusedToken');
    expect(spyTokenExpired).not.toHaveBeenCalledWith('lateToken');

    now += 30000;
    await websocketGateway['runHeartbeatSweep']();
    expect(spyTokenExpired).toHaveBeenCalledWith('lateToken');

  });
});
