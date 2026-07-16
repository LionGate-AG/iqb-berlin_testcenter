/* eslint-disable @typescript-eslint/dot-notation */
import { Test, TestingModule } from '@nestjs/testing';
import { HttpService } from '@nestjs/axios';
import { of, throwError } from 'rxjs';
import { WebSocket } from 'ws';
import { IncomingMessage } from 'http';
import { Testee } from './testee.interface';
import { WebsocketGateway } from '../common/websocket.gateway';
import { Command } from '../command/command.interface';
import { TesteeService } from './testee.service';
import { RedisService } from '../redis/redis.service';
import { FakeRedisService } from '../redis/redis.fake';
import { KEY } from '../redis/redis.constants';

let testeeService: TesteeService;
let websocketGateway: WebsocketGateway;
let redis: FakeRedisService;
let mockHttp: { post: jest.Mock };

const mockTestee : Testee = { token: 'testeeToken', testId: 5, disconnectNotificationUri: 'http://www.disconnectURI.de' };
const mockTestee2 : Testee = { token: 'testeeToken2', testId: 6, disconnectNotificationUri: 'http://www.disconnectURI.de' };

const makeWs = (): WebSocket => ({
  send: jest.fn(), close: jest.fn(), on: jest.fn(), terminate: jest.fn(), readyState: 1
} as unknown as WebSocket);

const connect = (token: string): void => {
  websocketGateway.handleConnection(makeWs(), { url: `x/ws?token=${token}` } as IncomingMessage);
};

const build = async (): Promise<void> => {
  redis = new FakeRedisService();
  mockHttp = { post: jest.fn(() => of(undefined)) };
  const module: TestingModule = await Test.createTestingModule({
    providers: [TesteeService, WebsocketGateway, RedisService, HttpService]
  })
    .overrideProvider(RedisService).useValue(redis)
    .overrideProvider(HttpService).useValue(mockHttp)
    .compile();

  testeeService = module.get<TesteeService>(TesteeService);
  websocketGateway = module.get<WebsocketGateway>(WebsocketGateway);
  await testeeService.onModuleInit();
};

describe('testeeService add and remove', () => {
  beforeEach(build);

  it('should be defined', () => {
    expect(testeeService).toBeDefined();
  });

  it('should add a testee (registration written to Redis)', async () => {
    await testeeService.addTestee(mockTestee);
    expect(await redis.hget<Testee>(KEY.testees, 'testeeToken')).toStrictEqual(mockTestee);
    expect(await redis.smembers(KEY.testeeTestId(5))).toContain('testeeToken');
  });

  it('should remove a testee', async () => {
    const spyDisconnectClient = jest.spyOn(websocketGateway, 'disconnectClient');

    await testeeService.addTestee(mockTestee);
    await testeeService.removeTestee(mockTestee.token);

    expect(await redis.hget(KEY.testees, 'testeeToken')).toBeNull();
    expect(await redis.smembers(KEY.testeeTestId(5))).toStrictEqual([]);
    expect(spyDisconnectClient).toHaveBeenCalledWith('testeeToken');
  });
});

describe('testeeService', () => {
  beforeEach(async () => {
    await build();
    await testeeService.addTestee(mockTestee);
    await testeeService.addTestee(mockTestee2);
  });

  it('should return an array of testees', async () => {
    expect(await testeeService.getTestees()).toEqual(expect.arrayContaining([mockTestee, mockTestee2]));
  });

  it('should delete all testees', async () => {
    await testeeService.clean();
    expect(await testeeService.getTestees()).toStrictEqual([]);
    expect(await redis.smembers(KEY.testeeTestId(5))).toStrictEqual([]);
    expect(await redis.smembers(KEY.testeeTestId(6))).toStrictEqual([]);
  });

  it('should broadcast commands to the addressed testees', async () => {
    connect(mockTestee.token); // alive + local
    const ws = (websocketGateway['clients'] as Map<string, WebSocket>).get(mockTestee.token)!;
    const spySend = jest.spyOn(ws, 'send');

    const mockCommand : Command = {
      keyword: 'pause', id: 'string id', arguments: ['arguments1', 'argument2'], timestamp: 12
    };
    await testeeService.broadcastCommandToTestees(mockCommand, [2, 3, 5, 19]);

    expect(spySend).toHaveBeenCalledWith(JSON.stringify({ event: 'commands', data: [mockCommand] }));
  });

  it('should return early when testee is unknown (notifyDisconnection)', async () => {
    await testeeService.notifyDisconnection('does-not-exist');
    expect(mockHttp.post).not.toHaveBeenCalled();
  });

  it('should post the disconnect notification (happy path)', async () => {
    const spyLogger = jest.spyOn(testeeService['logger'], 'log');
    await testeeService.notifyDisconnection(mockTestee.token);
    expect(mockHttp.post).toHaveBeenCalledWith(mockTestee.disconnectNotificationUri, {}, {});
    expect(spyLogger).toHaveBeenCalled();
  });

  it('should retry and then warn when the notification keeps failing', async () => {
    mockHttp.post = jest.fn(() => throwError(() => new Error('boom')));
    const spyWarn = jest.spyOn(testeeService['logger'], 'warn');
    await testeeService.notifyDisconnection(mockTestee.token);
    expect(mockHttp.post).toHaveBeenCalledTimes(3); // NOTIFY_MAX_RETRIES
    expect(spyWarn).toHaveBeenCalled();
  });
});
