import {
  MessageBody, OnGatewayConnection, OnGatewayDisconnect, OnGatewayInit,
  SubscribeMessage,
  WebSocketGateway,
  WebSocketServer,
  WsResponse
} from '@nestjs/websockets';
import { BehaviorSubject, Observable, Subject } from 'rxjs';
import { map } from 'rxjs/operators';
import { Server, WebSocket } from 'ws';
import { IncomingMessage } from 'http';
import { Logger, OnModuleDestroy } from '@nestjs/common';
import { BroadcastingEvent } from './interfaces';
import { RedisService } from '../redis/redis.service';

const sleep = (ms: number): Promise<void> => new Promise(resolve => { setTimeout(resolve, ms); });

@WebSocketGateway({ path: '/ws' })
export class WebsocketGateway implements
  OnGatewayConnection, OnGatewayDisconnect, OnGatewayInit, OnModuleDestroy {
  private readonly logger = new Logger(WebsocketGateway.name);
  private static readonly MAX_CONNECTIONS = 10000;
  private static readonly HEARTBEAT_INTERVAL = 30000;
  private static readonly SHUTDOWN_GRACE_MS = 5000;
  // How many clients the heartbeat sweep processes before yielding back to the event
  // loop. Without this, a large simultaneous-disconnect burst (e.g. an upstream outage
  // severing many sockets at once) gets discovered and cleaned up in one synchronous
  // pass over `clients`, blocking the whole pod -- including its own health probes --
  // for as long as the sweep takes. Observed: ~430 clients processed in ~430ms with the
  // old per-client log line; chunking bounds this regardless of burst size.
  private static readonly HEARTBEAT_BATCH_SIZE = 500;

  @WebSocketServer()
  private server!: Server; // magically injected

  // LOCAL only: the sockets this pod personally terminates. Never shared across pods.
  private clients = new Map<string, WebSocket>();
  // Tracks which local sockets answered the last ping (avoids stashing flags on the ws object).
  private aliveClients = new WeakSet<WebSocket>();
  private clientsCount$: BehaviorSubject<number> = new BehaviorSubject<number>(0);
  private clientLost$: Subject<string> = new Subject<string>();
  private heartbeatInterval: NodeJS.Timeout | null = null;
  // Guards against a second sweep starting while a very large one is still running.
  private heartbeatSweepRunning = false;

  constructor(private readonly redisService: RedisService) {}

  afterInit(server: Server) {
    this.startHeartbeat();
  }

  async onModuleDestroy(): Promise<void> {
    // Graceful shutdown: stop heartbeat and close all local sockets so clients reconnect to a
    // surviving pod, then wait a moment for them to notice the close before the process exits.
    if (this.heartbeatInterval) {
      clearInterval(this.heartbeatInterval);
      this.heartbeatInterval = null;
    }
    this.logger.log(`shutting down: disconnecting ${this.clients.size} local clients`);
    this.disconnectAll();
    await sleep(WebsocketGateway.SHUTDOWN_GRACE_MS);
  }

  private startHeartbeat() {
    this.heartbeatInterval = setInterval(() => this.onHeartbeatTick(), WebsocketGateway.HEARTBEAT_INTERVAL);
  }

  private onHeartbeatTick(): void {
    if (this.heartbeatSweepRunning) {
      this.logger.warn('Heartbeat sweep still running from the previous tick, skipping.');
      return;
    }
    this.heartbeatSweepRunning = true;
    this.runHeartbeatSweep()
      .catch(e => this.logger.error(`Heartbeat sweep failed: ${(e as Error).message}`))
      .finally(() => { this.heartbeatSweepRunning = false; });
  }

  private async runHeartbeatSweep(): Promise<void> {
    let staleCount = 0;
    let sinceYield = 0;
    // Tokens whose liveness marker needs refreshing, flushed per batch in ONE pipelined
    // Redis round trip rather than one SET per client. Per-client SETs meant a pod holding
    // N alive clients put N commands on the shared `pub` connection every tick, delaying
    // anything queued behind them -- including the readiness probe's ping() before it got
    // its own connection (see RedisService's constructor).
    let aliveBatch: string[] = [];

    const flushAliveBatch = async (): Promise<void> => {
      if (aliveBatch.length === 0) {
        return;
      }
      const batch = aliveBatch;
      aliveBatch = [];
      await this.redisService.setClientsAlive(batch).catch(() => {});
    };

    for (const [token, ws] of this.clients) {
      if (!this.aliveClients.has(ws)) {
        this.removeLocalClient(token, ws);
        ws.terminate();
        // Announce the loss exactly as handleDisconnect() does for a graceful close.
        // Without this, a heartbeat-terminated client never reached TesteeService's
        // clientLost$ subscription, so its `testees` hash entry and testee-testid:
        // set membership were never deleted -- they leaked permanently (observed:
        // HLEN testees at 162,595 against ~30k concurrent users, accumulated across
        // runs). handleDisconnect() cannot cover this: terminate() does fire a close
        // event, but that handler resolves the token by scanning `clients`, which
        // removeLocalClient() has already deleted from -- so it finds nothing and
        // emits nothing. Emitting here is therefore the only path, and it cannot
        // double-emit for the same reason.
        this.clientLost$.next(token);
        staleCount += 1;
      } else {
        this.aliveClients.delete(ws);
        // Refresh the cluster-wide liveness marker for sockets this pod holds.
        aliveBatch.push(token);
        ws.ping();
      }

      sinceYield += 1;
      if (sinceYield >= WebsocketGateway.HEARTBEAT_BATCH_SIZE) {
        sinceYield = 0;
        // eslint-disable-next-line no-await-in-loop
        await flushAliveBatch();
        // eslint-disable-next-line no-await-in-loop
        await new Promise<void>(resolve => { setImmediate(resolve); });
      }
    }

    await flushAliveBatch();

    // One summary line per tick instead of one per stale client -- logging each
    // individually was the actual cost of the blocking incident this fixes.
    if (staleCount > 0) {
      this.logger.warn(`Heartbeat: terminated ${staleCount} inactive client(s).`);
    }
  }

  handleConnection(client: WebSocket, message: IncomingMessage): void {
    if (this.clients.size >= WebsocketGateway.MAX_CONNECTIONS) {
      this.logger.error('Max connections reached. Rejecting client.');
      client.close(1013, 'Try again later');
      return;
    }

    let token: string;
    try {
      token = WebsocketGateway.getTokenFromUrl(message.url as string);
    } catch (e) {
      this.logger.error('Connection rejected due to invalid token', e);
      client.close(1008, 'Invalid token');
      return;
    }

    this.aliveClients.add(client);
    client.on('pong', () => {
      this.aliveClients.add(client);
    });

    this.clients.set(token, client);
    this.clientsCount$.next(this.clients.size);
    this.redisService.setClientAlive(token).catch(() => {});
    this.redisService.pushConnection(token).catch(() => {});
    this.logger.log(`client connected: ${token}`);
  }

  static getTokenFromUrl(url: string): string {
    const urlSearchParams = new URL(`xx://dumm.y/${url}`).searchParams;
    const token = urlSearchParams.get('token');
    if (!token) {
      throw new Error('No token!');
    }
    return token;
  }

  handleDisconnect(client: WebSocket): void {
    let disconnectedToken = '';
    for (const [token, ws] of this.clients.entries()) {
      if (ws === client) {
        disconnectedToken = token;
        break;
      }
    }

    if (disconnectedToken !== '') {
      this.removeLocalClient(disconnectedToken, client);
      this.clientLost$.next(disconnectedToken);
      this.logger.log(`client disconnected: ${disconnectedToken}`);
    }
  }

  private removeLocalClient(token: string, ws: WebSocket): void {
    this.clients.delete(token);
    this.aliveClients.delete(ws);
    this.clientsCount$.next(this.clients.size);
    this.redisService.deleteClientAlive(token).catch(() => {});
    this.redisService.removeConnection(token).catch(() => {});
  }

  /** Send to the given tokens, but only to sockets THIS pod actually holds and that are open. */
  broadcastToRegistered(tokens: string[], event: BroadcastingEvent, message: any): void {
    const payload = JSON.stringify({ event, data: message });

    tokens.forEach((token: string) => {
      const client = this.clients.get(token);
      if (client && client.readyState === WebSocket.OPEN) {
        this.logger.log(`sending to client: ${token}`);
        client.send(payload);
      }
    });
  }

  /** Of a cluster-wide token list, return the subset whose live socket is on this pod. */
  filterLocalTokens(tokens: string[]): string[] {
    return tokens.filter(token => this.clients.has(token));
  }

  /** Close a socket if this pod holds it. Idempotent and a no-op for tokens on other pods. */
  disconnectClient(token: string): void {
    const client = this.clients.get(token);
    if (client) {
      this.logger.log(`disconnect client: ${token}`);
      client.close();
      this.removeLocalClient(token, client);
    }
  }

  disconnectAll(): void {
    for (const [token] of this.clients.entries()) {
      this.disconnectClient(token);
    }
  }

  getDisconnectionObservable(): Observable<string> {
    return this.clientLost$.asObservable();
  }

  getClientTokens(): string[] {
    return Array.from(this.clients.keys());
  }

  @SubscribeMessage('subscribe:client.count')
  subscribeClientCount(@MessageBody() data: number): Observable<WsResponse<number>> {
    // Note: this is the LOCAL pod's connection count, not the cluster-wide total.
    return this.clientsCount$.pipe(map((count: number) => ({ event: 'client.count', data: count })));
  }
}
