/* eslint-disable @typescript-eslint/dot-notation */
import { Test, TestingModule } from '@nestjs/testing';
import { HttpException } from '@nestjs/common';
import { Request } from 'express';
import { TesteeController } from './testee.controller';
import { TesteeService } from './testee.service';
import { Testee } from './testee.interface';

let testeeController : TesteeController;

describe('TesteeController Post', () => {
  const mockTesteeService = {
    addTestee: jest.fn(),
    removeTestee: jest.fn()
  };

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [TesteeController],
      providers: [TesteeService]
    }).overrideProvider(TesteeService).useValue(mockTesteeService).compile();

    testeeController = module.get<TesteeController>(TesteeController);
  });

  it('should be defined', () => {
    expect(testeeController).toBeDefined();
  });

  it('should throw not testee data', async () => {
    const mockRequest = { body: { name: 'name' } } as Request;

    await expect(testeeController.testeeRegister(mockRequest)).rejects.toThrow(HttpException);
    await expect(testeeController.testeeRegister(mockRequest)).rejects.toThrow('not testee data');
  });

  it('should throw not testee data (no testId property)', async () => {
    const mockRequest = { body: { token: 'tokenString' } } as Request;

    await expect(testeeController.testeeRegister(mockRequest)).rejects.toThrow(HttpException);
    await expect(testeeController.testeeRegister(mockRequest)).rejects.toThrow('not testee data');
  });

  it('should throw not testee data (no token property)', async () => {
    const mockRequest = { body: { testId: 35 } } as Request;

    await expect(testeeController.testeeRegister(mockRequest)).rejects.toThrow(HttpException);
    await expect(testeeController.testeeRegister(mockRequest)).rejects.toThrow('not testee data');
  });

  it('should not throw any errors (happy path - register)', async () => {
    const mockRequest = {
      body: { token: 'token string', testId: 4, disconnectNotificationUri: 'testURI' }
    } as Request;

    await expect(testeeController.testeeRegister(mockRequest)).resolves.toBeUndefined();
    expect(mockTesteeService.addTestee).toHaveBeenCalled();
  });

  it('should throw no token in body', async () => {
    const mockRequest = { body: { testId: 5, disconnectNotificationUri: 'testURI' } } as Request;

    await expect(testeeController.testeeUnregister(mockRequest)).rejects.toThrow(HttpException);
    await expect(testeeController.testeeUnregister(mockRequest)).rejects.toThrow('no token in body');
  });

  it('should not throw any errors (happy path - unregister)', async () => {
    const spyLogger = jest.spyOn(testeeController['logger'], 'log');
    const mockRequest = {
      body: { token: 'token string', testId: 5, disconnectNotificationUri: 'testURI' }
    } as Request;

    await expect(testeeController.testeeUnregister(mockRequest)).resolves.toBeUndefined();
    expect(spyLogger).toHaveBeenCalled();
    expect(mockTesteeService.removeTestee).toHaveBeenCalled();
  });
});

describe('testeeController Get', () => {
  const testee1 : Testee = { token: 'testee token1', testId: 4, disconnectNotificationUri: 'testURI' };
  const testee2 : Testee = { token: 'testee token2', testId: 4, disconnectNotificationUri: 'testURI' };
  const testee3 : Testee = { token: 'testee token3', testId: 6, disconnectNotificationUri: 'testURI' };

  const testeeList = [testee1, testee2, testee3];

  const mockTesteeService = {
    getTestees: jest.fn(() => testeeList)
  };

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [TesteeController],
      providers: [TesteeService]
    }).overrideProvider(TesteeService).useValue(mockTesteeService).compile();

    testeeController = module.get<TesteeController>(TesteeController);
  });

  it('should return a list of testees', () => {
    const mockRequest = {} as Request;

    expect(testeeController.testees(mockRequest)).toStrictEqual(testeeList);
    expect(mockTesteeService.getTestees).toHaveBeenCalled();
  });
});
