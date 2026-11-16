import { HttpClient, HttpHeaders, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { Collection } from './models';

export type Query = Record<string, string | number | boolean | null | undefined | (string | number)[]>;

const LD = new HttpHeaders({ Accept: 'application/ld+json' });
const MERGE_PATCH = new HttpHeaders({ Accept: 'application/ld+json', 'Content-Type': 'application/merge-patch+json' });

/**
 * Thin typed client for the API Platform backend (JSON-LD / Hydra collections).
 */
@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);
  readonly base = '/api';

  list<T>(resource: string, query: Query = {}): Observable<Collection<T>> {
    return this.http
      .get<Collection<T>>(`${this.base}/${resource}`, { headers: LD, params: this.params(query) })
      .pipe(map((c) => ({ member: c.member ?? [], totalItems: c.totalItems ?? 0, view: c.view })));
  }

  get<T>(path: string, query: Query = {}): Observable<T> {
    return this.http.get<T>(this.url(path), { headers: LD, params: this.params(query) });
  }

  post<T>(path: string, body: unknown): Observable<T> {
    return this.http.post<T>(this.url(path), body, { headers: LD });
  }

  patch<T>(path: string, body: unknown): Observable<T> {
    return this.http.patch<T>(this.url(path), body, { headers: MERGE_PATCH });
  }

  delete(path: string): Observable<void> {
    return this.http.delete<void>(this.url(path), { headers: LD });
  }

  transition<T>(resource: string, id: number, transition: string, comment?: string | null): Observable<T> {
    return this.post<T>(`${resource}/${id}/transition`, { transition, comment: comment || null });
  }

  iri(resource: string, id: number): string {
    return `${this.base}/${resource}/${id}`;
  }

  private url(path: string): string {
    return path.startsWith('/api') ? path : `${this.base}/${path.replace(/^\//, '')}`;
  }

  private params(query: Query): HttpParams {
    let params = new HttpParams();
    for (const [key, value] of Object.entries(query)) {
      if (value === null || value === undefined || value === '') continue;
      if (Array.isArray(value)) value.forEach((v) => (params = params.append(`${key}[]`, String(v))));
      else params = params.set(key, String(value));
    }
    return params;
  }
}

/** Extracts a human-readable message from an API Platform / Symfony error payload. */
export function apiErrorMessage(error: unknown): string {
  const e = error as { status?: number; error?: { detail?: string; message?: string; violations?: { propertyPath: string; message: string }[] } };
  if (e?.error?.violations?.length) return e.error.violations.map((v) => v.message).join(' ');
  if (e?.error?.detail) return e.error.detail;
  if (e?.error?.message) return e.error.message;
  if (e?.status === 0) return 'Le serveur est injoignable. Vérifiez votre connexion.';
  return 'Une erreur inattendue est survenue.';
}

/** Field-level violations, keyed by property path. */
export function apiViolations(error: unknown): Record<string, string> {
  const violations = (error as { error?: { violations?: { propertyPath: string; message: string }[] } })?.error?.violations ?? [];
  return violations.reduce<Record<string, string>>((acc, v) => ({ ...acc, [v.propertyPath]: v.message }), {});
}
