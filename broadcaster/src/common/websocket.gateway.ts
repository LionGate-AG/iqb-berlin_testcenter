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

  @WebSocketServer()
  private server!: Server; // magically injected

  // LOCAL only: the sockets this pod personally terminates. Never shared across pods.
  private clients = new Map<string, WebSocket>();
  // Tracks which local sockets answered the last ping (avoids stashing flags on the ws object).
  private aliveClients = new WeakSet<WebSocket>();
  private clientsCount$: BehaviorSubject<number> = new BehaviorSubject<number>(0);
  private clientLost$: Subject<string> = new Subject<string>();
  private heartbeatInterval: NodeJS.Timeout | null = null;

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
    this.heartbeatInterval = setInterval(() => {
      this.clients.forEach((ws, token) => {
        if (!this.aliveClients.has(ws)) {
          this.logger.warn(`Client ${token} inactive, terminating.`);
          this.removeLocalClient(token, ws);
          ws.terminate();
          return;
        }

        this.aliveClients.delete(ws);
        // Refresh the cluster-wide liveness marker for sockets this pod holds.
        this.redisService.setClientAlive(token).catch(() => {});
        ws.ping();
      });
    }, WebsocketGateway.HEARTBEAT_INTERVAL);
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
