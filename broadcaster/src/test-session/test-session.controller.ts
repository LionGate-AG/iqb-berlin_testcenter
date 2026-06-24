import {
  Controller, Get, HttpException, Logger, Post, Req
} from '@nestjs/common';
import { Request } from 'express';
import { isSessionChange, TestSessionChange, isSessionChangeArray } from 'testcenter-common/interfaces/test-session-change.interface';
import { TestSessionService } from './test-session.service';

@Controller()
export class TestSessionController {
  constructor(
    private readonly dataService: TestSessionService
  ) {}

  private readonly logger = new Logger(TestSessionController.name);

  @Post('/push/session-change')
  async pushSessionChange(@Req() request: Request): Promise<void> {
    if (!isSessionChange(request.body)) {
      throw new HttpException('not session data', 400);
    }

    // this.logger.log('/push/session-change', JSON.stringify(request.body));
    await this.dataService.applySessionChange(request.body);
  }

  @Post('/push/session-changes')
  async pushSessionChanges(@Req() request: Request): Promise<void> {
    if (!isSessionChangeArray(request.body)) {
      throw new HttpException('not session data', 400);
    }

    await this.dataService.applySessionChanges(request.body);
  }

  @Get('/test-sessions')
  getTestSessions(): Promise<TestSessionChange[]> {
    return this.dataService.getTestSessions();
  }
}
