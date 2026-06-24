/* eslint-disable @typescript-eslint/dot-notation */
import { Test, TestingModule } from '@nestjs/testing';
import { Request } from 'express';
import { HttpException } from '@nestjs/common';
import { CommandController } from './command.controller';
import { TesteeService } from '../testee/testee.service';

describe('CommandControler', () => {
  let commandController: CommandController;

  const mockTesteeService = {
    broadcastCommandToTestees: jest.fn()
  };

  beforeEach(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [CommandController],
      providers: [TesteeService]
    })
      .overrideProvider(TesteeService)
      .useValue(mockTesteeService)
      .compile();

    commandController = module.get<CommandController>(CommandController);
  });

  it('should be defined', () => {
    expect(commandController).toBeDefined();
  });

  it('should throw invalid command data (no command property)', async () => {
    const mockNoCommand = {
      body: { testIds: [1, 2, 3], arguments: 'some arguments', timestamp: 12 }
    } as Request;

    await expect(commandController.postCommand(mockNoCommand)).rejects.toThrow(HttpException);
    await expect(commandController.postCommand(mockNoCommand)).rejects.toThrow('invalid command data');
  });

  it('should throw invalid command data (malformed command)', async () => {
    const mockMalformedRequest = { body: { command: { id: 12 } } } as Request;

    await expect(commandController.postCommand(mockMalformedRequest)).rejects.toThrow(HttpException);
    await expect(commandController.postCommand(mockMalformedRequest)).rejects.toThrow('invalid command data');
  });

  it('should throw no testIds given (no testIds property)', async () => {
    const mockNoTestIDs = {
      body: { command: { keyword: 'pause', id: 12, arguments: 'some arguments', timestamp: 12 } }
    } as Request;

    await expect(commandController.postCommand(mockNoTestIDs)).rejects.toThrow(HttpException);
    await expect(commandController.postCommand(mockNoTestIDs)).rejects.toThrow('no testIds given');
  });

  it('should throw no testIds given (array test)', async () => {
    const mockNoArrayTestID = {
      body: { command: { keyword: 'pause', id: 12, arguments: 'some arguments', timestamp: 12 }, testIds: 4 }
    } as Request;

    await expect(commandController.postCommand(mockNoArrayTestID)).rejects.toThrow(HttpException);
    await expect(commandController.postCommand(mockNoArrayTestID)).rejects.toThrow('no testIds given');
  });

  it('Should not throw any errors (happy path)', async () => {
    const spyLogger = jest.spyOn(commandController['logger'], 'log');
    const mockValidRequest = {
      body: {
        command: {
          keyword: 'pause', id: 'string id', arguments: ['arguments1', 'argument2'], timestamp: 12
        },
        testIds: [5]
      }
    } as Request;

    await expect(commandController.postCommand(mockValidRequest)).resolves.toBeUndefined();
    expect(spyLogger).toHaveBeenCalled();
    expect(mockTesteeService.broadcastCommandToTestees)
      .toHaveBeenCalledWith(mockValidRequest.body.command, mockValidRequest.body.testIds);
  });
});
