import {
  Controller, Post, Logger, Get, HttpCode
} from '@nestjs/common';
import { TestSessionService } from '../test-session/test-session.service';
import { TesteeService } from '../testee/testee.service';
import { RedisService } from '../redis/redis.service';
import { CHANNEL } from '../redis/redis.constants';

@Controller()
export class SystemController {
  constructor(
    private readonly dataService: TestSessionService,
    private readonly testeeService: TesteeService,
    private readonly redisService: RedisService
  ) {}

  private readonly logger = new Logger(SystemController.name);

  @Post('/system/clean')
  async clean(): Promise<void> {
    this.logger.warn('clean system');
    // Tell every pod (including this one, via its own subscription) to drop all local sockets.
    await this.redisService.publish(CHANNEL.systemClean, {});
    await this.dataService.clean();
    await this.testeeService.clean();
  }

  @Get('')
  @HttpCode(200)
  // eslint-disable-next-line class-methods-use-this
  root(): void {
    this.logger.log('ping');
  }
}
