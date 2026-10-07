import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router, UrlTree } from '@angular/router';
import { of, throwError } from 'rxjs';
import { TokenLoginActivateGuard } from './app-route-guards';
import { MainDataService } from './shared/shared.module';
import { BackendService } from './backend.service';
import { AuthData } from './app.interfaces';
import { CustomtextService } from './shared/services/customtext/customtext.service';
// MainDataService depends on its own (differently-located, identically-named) BackendService, distinct from
// the one TokenLoginActivateGuard and this spec otherwise use
import { BackendService as MainDataServiceBackendService } from './shared/services/backend.service';

describe('TokenLoginActivateGuard', () => {
  let guard: TokenLoginActivateGuard;
  let mockMainDataService: jasmine.SpyObj<MainDataService>;
  let mockBackendService: jasmine.SpyObj<BackendService>;
  let mockRouter: jasmine.SpyObj<Router>;

  beforeEach(() => {
    mockMainDataService = jasmine.createSpyObj('MainDataService', ['setAuthData', 'clearAuthData']);
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

  it('redirects to /r/login and clears the primed auth state when getSessionData fails', (done) => {
    mockBackendService.getSessionData.and.returnValue(throwError(() => new Error('token invalid')));

    const result = guard.canActivate({ paramMap: { get: () => 'bare-token' } } as any);

    (result as unknown as ReturnType<BackendService['getSessionData']>).subscribe(value => {
      expect(mockMainDataService.clearAuthData).toHaveBeenCalled();
      expect(value as any).toEqual(['/r/login']);
      done();
    });
  });
});

describe('TokenLoginActivateGuard with real MainDataService', () => {
  // regression test for a bug found in code review: setAuthData({ token }) primes localStorage/the in-memory
  // subject with a partial AuthData BEFORE getSessionData() validates the token; if validation then fails, that
  // partial object must not be left behind, or the next page load (RouteDispatcherActivateGuard etc.) crashes
  // on a truthy-but-incomplete AuthData. A spied-out MainDataService can't catch this, hence the real service here.
  const localStorageAuthDataKey = 'iqb-tc-a';
  let guard: TokenLoginActivateGuard;
  let mainDataService: MainDataService;
  let mockBackendService: jasmine.SpyObj<BackendService>;
  let mockRouter: jasmine.SpyObj<Router>;

  beforeEach(() => {
    localStorage.removeItem(localStorageAuthDataKey);
    mockBackendService = jasmine.createSpyObj('BackendService', ['getSessionData']);
    mockRouter = jasmine.createSpyObj('Router', ['createUrlTree']);
    mockRouter.createUrlTree.and.callFake((commands: unknown[]) => commands as unknown as UrlTree);

    TestBed.configureTestingModule({
      providers: [
        TokenLoginActivateGuard,
        MainDataService,
        { provide: BackendService, useValue: mockBackendService },
        { provide: Router, useValue: mockRouter },
        { provide: ActivatedRoute, useValue: { queryParams: of({}) } },
        { provide: CustomtextService, useValue: jasmine.createSpyObj('CustomtextService', ['addCustomTexts']) },
        { provide: MainDataServiceBackendService, useValue: {} },
        { provide: 'IS_PRODUCTION_MODE', useValue: false }
      ]
    });

    guard = TestBed.inject(TokenLoginActivateGuard);
    mainDataService = TestBed.inject(MainDataService);
  });

  afterEach(() => {
    localStorage.removeItem(localStorageAuthDataKey);
  });

  it('leaves no trace of the primed token in memory or localStorage after getSessionData fails', (done) => {
    mockBackendService.getSessionData.and.returnValue(throwError(() => new Error('token invalid')));

    const result = guard.canActivate({ paramMap: { get: () => 'bare-token' } } as any);

    (result as unknown as ReturnType<BackendService['getSessionData']>).subscribe(() => {
      expect(mainDataService.getAuthData()).toBeNull();
      expect(localStorage.getItem(localStorageAuthDataKey)).toBeNull();
      done();
    });
  });
});
