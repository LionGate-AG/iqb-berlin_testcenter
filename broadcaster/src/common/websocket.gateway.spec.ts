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

  const connectClients = (tokens: string[]): WebSocket[] => {
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
    const [client] = connectClients(['t1']);
    const spyWarn = jest.spyOn(websocketGateway['logger'], 'warn');

    await websocketGateway['runHeartbeatSweep']();

    expect(client.terminate).not.toHaveBeenCalled();
    expect(client.ping).toHaveBeenCalled();
    expect(websocketGateway['clients'].has('t1')).toBe(true);
    expect(spyWarn).not.toHaveBeenCalled();
  });

  it('should terminate clients that missed a pong and log a single summary line (not one per client)', async () => {
    const tokens = ['t1', 't2', 't3'];
    const clients = connectClients(tokens);

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
    const [client] = connectClients(['t1']);

    await websocketGateway['runHeartbeatSweep'](); // tick 1
    (websocketGateway['aliveClients'] as WeakSet<WebSocket>).add(client); // simulate a pong
    await websocketGateway['runHeartbeatSweep'](); // tick 2

    expect(client.terminate).not.toHaveBeenCalled();
    expect(websocketGateway['clients'].has('t1')).toBe(true);
  });

  it('should process every client correctly across multiple batches', async () => {
    const GatewayCtor = WebsocketGateway as unknown as { HEARTBEAT_BATCH_SIZE: number };
    const originalBatchSize = GatewayCtor.HEARTBEAT_BATCH_SIZE;
    GatewayCtor.HEARTBEAT_BATCH_SIZE = 2; // force several yields for a small client count

    try {
      const tokens = Array.from({ length: 5 }, (_, i) => `t${i}`);
      const clients = connectClients(tokens);

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
