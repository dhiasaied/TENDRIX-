import { Component, HostListener, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ApiService, apiErrorMessage } from '../core/api.service';
import { TRANSITIONS, TransitionMeta } from '../core/labels';
import { Workflowed } from '../core/models';
import { ToastService } from '../core/toast.service';
import { IconComponent } from './icon.component';

/**
 * Renders the lifecycle actions the backend says the current user may perform
 * ("availableTransitions"), with confirmation / mandatory justification when needed.
 */
@Component({
  selector: 'app-workflow-actions',
  imports: [FormsModule, IconComponent],
  template: `
    <div class="actions">
      @for (a of actions(); track a.name) {
        <button [class]="'btn ' + buttonClass(a.meta)" [disabled]="busy()" (click)="start(a.name, a.meta)">
          @if (busy() && running() === a.name) { <app-icon name="loader" class="spin" /> } @else { <app-icon [name]="a.meta.icon" /> }
          {{ a.meta.label }}
        </button>
      }
    </div>

    @if (pending(); as p) {
      <div class="modal-backdrop" (click)="cancel()">
        <div class="modal" role="dialog" aria-modal="true" [attr.aria-labelledby]="'wf-title'" (click)="$event.stopPropagation()">
          <div class="modal-head">
            <div class="modal-icon" [class.danger]="p.meta.style === 'danger'"><app-icon [name]="p.meta.icon" [size]="18" /></div>
            <div>
              <h3 id="wf-title">{{ p.meta.label }}</h3>
              <p>{{ p.meta.confirm ?? 'Cette action sera tracée dans l\\'historique.' }}</p>
            </div>
          </div>
          <label class="field">
            <span>{{ p.meta.commentLabel ?? 'Commentaire' }} @if (!p.meta.commentRequired) { <small>(facultatif)</small> }</span>
            <textarea rows="4" [(ngModel)]="comment" maxlength="2000" autofocus></textarea>
            @if (p.meta.commentRequired) { <span class="hint">Ce motif est communiqué à l'autre partie.</span> }
          </label>
          <div class="modal-actions">
            <button class="btn btn-ghost" (click)="cancel()">Annuler</button>
            <button [class]="'btn ' + (p.meta.style === 'danger' ? 'btn-danger' : 'btn-primary')" [disabled]="busy() || (p.meta.commentRequired && !comment.trim())" (click)="confirm()">
              @if (busy()) { <app-icon name="loader" class="spin" /> }
              Confirmer
            </button>
          </div>
        </div>
      </div>
    }
  `,
})
export class WorkflowActionsComponent {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastService);

  /** API resource path segment: tenders | proposals | contracts | invoices. */
  readonly resource = input.required<string>();
  readonly kind = input.required<'tender' | 'proposal' | 'contract' | 'invoice'>();
  readonly subject = input.required<Workflowed>();
  readonly changed = output<Workflowed>();

  readonly busy = signal(false);
  readonly running = signal<string | null>(null);
  readonly pending = signal<{ name: string; meta: TransitionMeta } | null>(null);
  comment = '';

  readonly actions = computed(() =>
    (this.subject().availableTransitions ?? [])
      .map((name) => ({ name, meta: TRANSITIONS[this.kind()]?.[name] ?? { icon: 'arrow-right' as const, label: name, style: 'secondary' as const } }))
      .sort((a, b) => order(a.meta) - order(b.meta)),
  );

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this.pending() && !this.busy()) this.cancel();
  }

  buttonClass(meta: TransitionMeta): string {
    return { primary: 'btn-primary', secondary: 'btn-secondary', danger: 'btn-danger' }[meta.style];
  }

  start(name: string, meta: TransitionMeta): void {
    if (meta.commentRequired || meta.confirm) {
      this.comment = '';
      this.pending.set({ name, meta });
    } else {
      this.run(name, null, meta);
    }
  }

  confirm(): void {
    const p = this.pending();
    if (p) this.run(p.name, this.comment, p.meta);
  }

  cancel(): void {
    this.pending.set(null);
  }

  private run(name: string, comment: string | null, meta: TransitionMeta): void {
    this.busy.set(true);
    this.running.set(name);
    this.api.transition<Workflowed>(this.resource(), this.subject().id, name, comment).subscribe({
      next: (updated) => {
        this.busy.set(false);
        this.running.set(null);
        this.pending.set(null);
        this.toast.success(`${meta.label} : c'est fait.`);
        this.changed.emit(updated);
      },
      error: (e) => {
        this.busy.set(false);
        this.running.set(null);
        this.toast.error(apiErrorMessage(e));
      },
    });
  }
}

function order(meta: TransitionMeta): number {
  return { primary: 0, secondary: 1, danger: 2 }[meta.style];
}
