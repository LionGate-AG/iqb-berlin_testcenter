import { TestBed } from '@angular/core/testing';
import { Router, UrlTree } from '@angular/router';
import { of, throwError } from 'rxjs';
import { TokenLoginActivateGuard } from './app-route-guards';
import { MainDataService } from './shared/shared.module';
import { BackendService } from './backend.service';
import { AuthData } from './app.interfaces';

describe('TokenLoginActivateGuard', () => {
  let guard: TokenLoginActivateGuard;
  let mockMainDataService: jasmine.SpyObj<MainDataService>;
  let mockBackendService: jasmine.SpyObj<BackendService>;
  let mockRouter: jasmine.SpyObj<Router>;

  beforeEach(() => {
    mockMainDataService = jasmine.createSpyObj('MainDataService', ['setAuthData']);
    mockBackendService = jasmine.createSpyObj('BackendService', ['getSessionData']);
    mockRouter = jasmine.createSpyObj('Router', ['createUrlTree']);
    mockRouter.createUrlTree.and.callFake((commands: unknown[]) => commands as unknown as UrlTree);

    TestBed.configureTestingModule({
      providers: [
        TokenLoginActivateGuard,
        { provide: MainDataService, useValue: mockMainDataService },
        { provide: BackendService, useValue: mockBackendService },
        { provide: Router, useValue: mockRouter }
      ]
    });

    guard = TestBed.inject(TokenLoginActivateGuard);
  });

  it('redirects to /r/login when no token param is present', () => {
    const result: any = guard.canActivate({ paramMap: { get: () => null } } as any);

    expect(result).toEqual(['/r/login']);
    expect(mockMainDataService.setAuthData).not.toHaveBeenCalled();
    expect(mockBackendService.getSessionData).not.toHaveBeenCalled();
  });

  it('primes AuthData with the bare token, hydrates it via getSessionData, and redirects to /r', (done) => {
    const fullAuthData = { token: 'real-token', claims: {} } as unknown as AuthData;
    mockBackendService.getSessionData.and.returnValue(of(fullAuthData));

    const result = guard.canActivate({ paramMap: { get: () => 'bare-token' } } as any);

    (result as unknown as ReturnType<BackendService['getSessionData']>).subscribe(value => {
      expect(mockMainDataService.setAuthData).toHaveBeenCalledWith({ token: 'bare-token' } as AuthData);
      expect(mockMainDataService.setAuthData).toHaveBeenCalledWith(fullAuthData);
      expect(value as any).toEqual(['/r']);
      done();
    });
  });

  it('redirects to /r/login when getSessionData fails', (done) => {
    mockBackendService.getSessionData.and.returnValue(throwError(() => new Error('token invalid')));

    const result = guard.canActivate({ paramMap: { get: () => 'bare-token' } } as any);

    (result as unknown as ReturnType<BackendService['getSessionData']>).subscribe(value => {
      expect(value as any).toEqual(['/r/login']);
      done();
    });
  });
});
