import { Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { ThemeService } from './core/theme.service';
import { ToastService } from './core/toast.service';
import { IconComponent } from './shared/icon.component';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, IconComponent],
  template: `
    <router-outlet />
    <div class="toasts" aria-live="polite" aria-atomic="false">
      @for (t of toast.toasts(); track t.id) {
        <div [class]="'toast toast-' + t.kind" (click)="toast.dismiss(t.id)" role="status">
          <app-icon class="toast-icon" [name]="t.kind === 'success' ? 'circle-check' : t.kind === 'error' ? 'circle-alert' : 'info'" [size]="18" />
          <p>{{ t.text }}</p>
          <app-icon name="x" [size]="14" class="subtle" />
        </div>
      }
    </div>
  `,
})
export class AppComponent {
  readonly toast = inject(ToastService);
  // Instantiated here so the theme is applied app-wide from the start.
  private readonly theme = inject(ThemeService);
}
