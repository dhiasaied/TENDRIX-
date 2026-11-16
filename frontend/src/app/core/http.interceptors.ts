import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, throwError } from 'rxjs';
import { AuthService } from './auth.service';
import { ToastService } from './toast.service';

/**
 * The session cookie is sent by the browser automatically (same origin, SameSite=Strict), so no
 * credential is ever handled in JavaScript. This interceptor only reacts to session expiry and
 * surfaces transport errors.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth = inject(AuthService);
  const toast = inject(ToastService);

  return next(req).pipe(
    catchError((error: HttpErrorResponse) => {
      const silent = req.url.endsWith('/auth/login') || req.url.endsWith('/auth/logout') || req.url === '/api/me';
      if (error.status === 401 && !silent) {
        if (auth.isAuthenticated()) toast.info('Votre session a expiré, merci de vous reconnecter.');
        auth.clear();
      } else if (error.status >= 500 || error.status === 0) {
        toast.error(error.status === 0 ? 'Le serveur est injoignable.' : 'Erreur serveur, réessayez dans quelques instants.');
      } else if (error.status === 429) {
        toast.error('Trop de requêtes, merci de patienter avant de réessayer.');
      }
      return throwError(() => error);
    }),
  );
};
