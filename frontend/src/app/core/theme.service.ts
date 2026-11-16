import { Injectable, effect, signal } from '@angular/core';

export type ThemeChoice = 'light' | 'dark' | 'system';

const KEY = 'tendrix.theme';

/**
 * Light / dark / system theme. The resolved theme is written to <html data-theme>, which the
 * design tokens in styles.scss key off. index.html applies the stored choice before Angular boots
 * to avoid a flash of the wrong theme.
 */
@Injectable({ providedIn: 'root' })
export class ThemeService {
  readonly choice = signal<ThemeChoice>(read());
  private readonly media = window.matchMedia('(prefers-color-scheme: dark)');
  private readonly systemDark = signal(this.media.matches);

  constructor() {
    this.media.addEventListener('change', (e) => this.systemDark.set(e.matches));
    effect(() => {
      const choice = this.choice();
      const dark = choice === 'dark' || (choice === 'system' && this.systemDark());
      document.documentElement.dataset['theme'] = dark ? 'dark' : 'light';
      try {
        localStorage.setItem(KEY, choice);
      } catch {
        /* storage unavailable: keep the choice for this session only */
      }
    });
  }

  set(choice: ThemeChoice): void {
    this.choice.set(choice);
  }
}

function read(): ThemeChoice {
  try {
    const v = localStorage.getItem(KEY);
    return v === 'light' || v === 'dark' ? v : 'system';
  } catch {
    return 'system';
  }
}
