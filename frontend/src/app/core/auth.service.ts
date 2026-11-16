import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, catchError, finalize, map, of, tap } from 'rxjs';
import { Role, User } from './models';

/** Non-sensitive identity returned by the API; the session token itself lives in an httpOnly cookie. */
export interface SessionUser {
  id: number;
  email: string;
  fullName: string;
  roles: Role[];
  organization: { id: number; name: string; type: 'buyer' | 'supplier' };
}

/**
 * Session state for the SPA. The JWT is set by the API as an httpOnly, SameSite=Strict cookie:
 * it is never readable from JavaScript, so an XSS cannot exfiltrate it. The app only keeps the
 * public profile in memory and restores it from /api/me on startup.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);

  readonly session = signal<SessionUser | null>(null);
  readonly profile = signal<User | null>(null);

  readonly isAuthenticated = computed(() => this.session() !== null);
  readonly roles = computed(() => this.session()?.roles ?? []);
  readonly isAdmin = computed(() => this.roles().includes('ROLE_ADMIN'));
  readonly isBuyer = computed(() => this.roles().includes('ROLE_BUYER'));
  readonly isRequester = computed(() => this.roles().includes('ROLE_REQUESTER'));
  readonly isSupplier = computed(() => this.roles().includes('ROLE_SUPPLIER'));
  /** Buyer side of the marketplace: procurement officers and requesters. */
  readonly isClientSide = computed(() => this.isBuyer() || this.isRequester());
  readonly displayName = computed(() => this.session()?.fullName ?? '');
  readonly organization = computed(() => this.session()?.organization ?? null);

  hasAnyRole(roles: Role[]): boolean {
    return roles.some((r) => this.roles().includes(r));
  }

  /** Restores the session from the cookie, if any (called once at startup). Never throws. */
  restore(): Observable<boolean> {
    return this.http.get<User>('/api/me', { headers: { Accept: 'application/json' } }).pipe(
      tap((user) => this.applyProfile(user)),
      map(() => true),
      catchError(() => {
        this.session.set(null);
        return of(false);
      }),
    );
  }

  login(email: string, password: string): Observable<SessionUser> {
    return this.http.post<{ user: SessionUser }>('/api/auth/login', { email, password }).pipe(
      map((res) => res.user),
      tap((user) => {
        this.session.set(user);
        this.loadProfile();
      }),
    );
  }

  register(payload: Record<string, unknown>): Observable<unknown> {
    return this.http.post('/api/auth/register', payload);
  }

  loadProfile(): void {
    if (!this.isAuthenticated()) return;
    this.http.get<User>('/api/me', { headers: { Accept: 'application/json' } }).subscribe({ next: (user) => this.applyProfile(user) });
  }

  /** Revokes the session server-side (all devices) and clears local state. */
  logout(): void {
    this.http
      .post('/api/auth/logout', null)
      .pipe(finalize(() => this.clear()))
      .subscribe({ error: () => undefined });
  }

  /** Local sign-out only, e.g. when the API reports the session has expired. */
  clear(redirect = true): void {
    this.session.set(null);
    this.profile.set(null);
    if (redirect) this.router.navigate(['/login']);
  }

  private applyProfile(user: User): void {
    this.profile.set(user);
    this.session.set({
      id: user.id,
      email: user.email,
      fullName: user.fullName,
      roles: user.roles,
      organization: { id: user.organization.id, name: user.organization.name, type: user.organization.type },
    });
  }
}

/** Only same-app paths are accepted as post-login redirects (prevents open redirects). */
export function safeReturnUrl(value: string | null): string {
  return value && value.startsWith('/') && !value.startsWith('//') && !value.startsWith('/\\') ? value : '/dashboard';
}
