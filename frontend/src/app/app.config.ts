import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, LOCALE_ID, inject, provideAppInitializer, provideZoneChangeDetection } from '@angular/core';
import { registerLocaleData } from '@angular/common';
import localeFr from '@angular/common/locales/fr';
import { provideRouter, withComponentInputBinding, withInMemoryScrolling } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { routes } from './app.routes';
import { AuthService } from './core/auth.service';
import { authInterceptor } from './core/http.interceptors';

registerLocaleData(localeFr);

export const appConfig: ApplicationConfig = {
  providers: [
    provideZoneChangeDetection({ eventCoalescing: true }),
    provideRouter(routes, withComponentInputBinding(), withInMemoryScrolling({ scrollPositionRestoration: 'top' })),
    // CSRF: the session cookie is SameSite=Strict and the API only accepts JSON bodies (a cross-site
    // form cannot produce them without a CORS preflight), so no XSRF token is needed.
    provideHttpClient(withInterceptors([authInterceptor])),
    // Restore the session from the httpOnly cookie before the first navigation, so guards see it.
    provideAppInitializer(() => firstValueFrom(inject(AuthService).restore())),
    { provide: LOCALE_ID, useValue: 'fr-FR' },
  ],
};
