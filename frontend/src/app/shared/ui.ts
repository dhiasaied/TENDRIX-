import { DecimalPipe } from '@angular/common';
import {
  AfterViewInit, Component, Directive, ElementRef, OnDestroy, Pipe, PipeTransform, computed, forwardRef, inject, input, model, output, signal,
} from '@angular/core';
import { ControlValueAccessor, FormsModule, NG_VALUE_ACCESSOR } from '@angular/forms';
import { STATUS } from '../core/labels';
import { IconComponent, IconName } from './icon.component';

/** Formats euros the French way: 12 345 € (or with cents when asked). */
@Pipe({ name: 'eur' })
export class EurPipe implements PipeTransform {
  transform(value: number | null | undefined, cents = false): string {
    if (value === null || value === undefined) return '—';
    return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: cents ? 2 : 0, minimumFractionDigits: cents ? 2 : 0 }).format(value);
  }
}

/** Compact euros for axes and tight spaces: 12,3 k€. */
@Pipe({ name: 'eurShort' })
export class EurShortPipe implements PipeTransform {
  transform(value: number | null | undefined): string {
    if (value === null || value === undefined) return '—';
    return new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 }).format(value) + ' €';
  }
}

@Pipe({ name: 'frDate' })
export class FrDatePipe implements PipeTransform {
  transform(value: string | null | undefined, withTime = false): string {
    if (!value) return '—';
    return new Intl.DateTimeFormat('fr-FR', withTime ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' }).format(new Date(value));
  }
}

@Pipe({ name: 'period' })
export class PeriodPipe implements PipeTransform {
  transform(value: string | null | undefined): string {
    if (!value) return '—';
    const [y, m] = value.split('-').map(Number);
    return new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' }).format(new Date(y, m - 1, 1));
  }
}

@Pipe({ name: 'ago' })
export class AgoPipe implements PipeTransform {
  transform(value: string | null | undefined): string {
    if (!value) return '';
    const seconds = Math.round((Date.now() - new Date(value).getTime()) / 1000);
    const rtf = new Intl.RelativeTimeFormat('fr', { numeric: 'auto' });
    const steps: [number, Intl.RelativeTimeFormatUnit][] = [[60, 'second'], [60, 'minute'], [24, 'hour'], [30, 'day'], [12, 'month'], [Infinity, 'year']];
    let amount = seconds;
    for (const [size, unit] of steps) {
      if (Math.abs(amount) < size) return rtf.format(-amount, unit);
      amount = Math.round(amount / size);
    }
    return '';
  }
}

@Pipe({ name: 'initials' })
export class InitialsPipe implements PipeTransform {
  transform(value: string | null | undefined): string {
    return (value ?? '').split(/\s+/).filter(Boolean).map((p) => p[0]).slice(0, 2).join('').toUpperCase();
  }
}

@Component({
  selector: 'app-status',
  template: `<span [class]="'badge tone-' + meta()[1]">{{ meta()[0] }}</span>`,
})
export class StatusBadgeComponent {
  readonly kind = input.required<string>();
  readonly status = input.required<string>();
  readonly meta = computed(() => STATUS[this.kind()]?.[this.status()] ?? [this.status(), 'neutral']);
}

/** Adequacy score ring (0-100), drawn with an SVG stroke: solid colors only. */
@Component({
  selector: 'app-score',
  imports: [IconComponent],
  template: `
    @if (value() === null || value() === undefined) {
      <span class="score-pending" [style.width.px]="size()" [style.height.px]="size()" title="Analyse IA en cours">
        <app-icon name="loader" class="spin" />
      </span>
    } @else {
      <span [class]="'score ' + tone() + (size() > 50 ? ' lg' : '')" role="img" [attr.aria-label]="'Adéquation ' + value() + ' sur 100'">
        <svg [attr.width]="size()" [attr.height]="size()" [attr.viewBox]="'0 0 ' + size() + ' ' + size()">
          <circle class="track" [attr.cx]="size() / 2" [attr.cy]="size() / 2" [attr.r]="radius()" fill="none" [attr.stroke-width]="stroke()" />
          <circle class="value" [attr.cx]="size() / 2" [attr.cy]="size() / 2" [attr.r]="radius()" fill="none" [attr.stroke-width]="stroke()"
                  stroke-linecap="round" [attr.stroke-dasharray]="circumference()" [attr.stroke-dashoffset]="offset()" />
        </svg>
        <b>{{ value() }}</b>
      </span>
    }
  `,
})
export class ScoreComponent {
  readonly value = input<number | null | undefined>(null);
  readonly size = input(40);
  readonly stroke = computed(() => (this.size() > 50 ? 5 : 3.5));
  readonly radius = computed(() => (this.size() - this.stroke()) / 2);
  readonly circumference = computed(() => 2 * Math.PI * this.radius());
  readonly offset = computed(() => this.circumference() * (1 - Math.min(100, Math.max(0, this.value() ?? 0)) / 100));
  readonly tone = computed(() => {
    const v = this.value() ?? 0;
    return v >= 80 ? 'high' : v >= 60 ? 'mid' : 'low';
  });
}

@Component({
  selector: 'app-stars',
  imports: [DecimalPipe, IconComponent],
  template: `
    <span class="stars" role="img" [attr.aria-label]="(value() ?? 0) + ' sur 5'">
      @for (i of [1, 2, 3, 4, 5]; track i) {
        <app-icon name="star" [size]="size()" [class.on]="(value() ?? 0) >= i - 0.5" />
      }
      @if (showValue() && value()) {
        <small>{{ value() | number: '1.1-1' }}</small>
      }
    </span>
  `,
})
export class StarsComponent {
  readonly value = input<number | null | undefined>(null);
  readonly showValue = input(true);
  readonly size = input(14);
}

/** Clickable 1-5 rating input. */
@Component({
  selector: 'app-rating-input',
  imports: [IconComponent],
  template: `
    <span class="stars input" role="radiogroup" [attr.aria-label]="label()">
      @for (i of [1, 2, 3, 4, 5]; track i) {
        <button type="button" role="radio" [attr.aria-checked]="value() === i" [class.on]="value() >= i" (click)="value.set(i)" [attr.aria-label]="i + ' sur 5'">
          <app-icon name="star" [size]="18" />
        </button>
      }
    </span>
  `,
})
export class RatingInputComponent {
  readonly value = model(3);
  readonly label = input('Note');
}

/**
 * Range summary + numbered pages (with ellipses past 7 pages). Same inputs/outputs as before,
 * so every list keeps its own loading logic.
 */
@Component({
  selector: 'app-pagination',
  imports: [IconComponent],
  template: `
    @if (total() > 0) {
      <nav class="pagination" aria-label="Pagination">
        <span class="pagination-range">
          <b>{{ from() }}–{{ to() }}</b> sur <b>{{ total() }}</b> {{ total() > 1 ? 'résultats' : 'résultat' }}
        </span>
        @if (pages() > 1) {
          <div class="pages">
            <button class="page-btn" [disabled]="page() <= 1" (click)="go(page() - 1)" aria-label="Page précédente">
              <app-icon name="chevron-left" [size]="15" />
            </button>
            @for (p of slots(); track $index) {
              @if (p === null) {
                <span class="page-gap" aria-hidden="true">…</span>
              } @else {
                <button class="page-btn" [class.on]="p === page()" [attr.aria-current]="p === page() ? 'page' : null" (click)="go(p)">{{ p }}</button>
              }
            }
            <button class="page-btn" [disabled]="page() >= pages()" (click)="go(page() + 1)" aria-label="Page suivante">
              <app-icon name="chevron-right" [size]="15" />
            </button>
          </div>
        }
      </nav>
    }
  `,
})
export class PaginationComponent {
  readonly page = input(1);
  readonly total = input(0);
  readonly perPage = input(20);
  readonly pageChange = output<number>();
  readonly pages = computed(() => Math.max(1, Math.ceil(this.total() / this.perPage())));
  readonly from = computed(() => (this.page() - 1) * this.perPage() + 1);
  readonly to = computed(() => Math.min(this.total(), this.page() * this.perPage()));

  /** Page numbers to display; null marks an ellipsis. */
  readonly slots = computed<(number | null)[]>(() => {
    const last = this.pages();
    const current = this.page();
    if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1);
    const around = [current - 1, current, current + 1].filter((p) => p > 1 && p < last);
    const slots: (number | null)[] = [1];
    if (around[0] > 2) slots.push(null);
    slots.push(...around);
    if (around[around.length - 1] < last - 1) slots.push(null);
    slots.push(last);
    return slots;
  });

  go(p: number): void {
    if (p >= 1 && p <= this.pages() && p !== this.page()) this.pageChange.emit(p);
  }
}

/** Placeholder rows shaped like a table while data loads. */
@Component({
  selector: 'app-table-skeleton',
  template: `
    <div class="table-skeleton" aria-busy="true" aria-label="Chargement">
      @for (r of rowsArray(); track r) {
        <div class="ts-row" [style.animation-delay.ms]="r * 70">
          @for (c of colsArray(); track c) { <span class="ts-cell" [style.flex]="c === 0 ? 2 : 1"></span> }
        </div>
      }
    </div>
  `,
})
export class TableSkeletonComponent {
  readonly rows = input(6);
  readonly cols = input(5);
  readonly rowsArray = computed(() => Array.from({ length: this.rows() }, (_, i) => i));
  readonly colsArray = computed(() => Array.from({ length: this.cols() }, (_, i) => i));
}

/**
 * Search field with icon, clear button and a "/" keyboard shortcut. Works with ngModel, so
 * existing (ngModelChange) debounce logic is untouched.
 */
@Component({
  selector: 'app-search',
  imports: [FormsModule, IconComponent],
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => SearchInputComponent), multi: true }],
  host: { class: 'search', '(document:keydown)': 'onShortcut($event)' },
  template: `
    <app-icon name="search" class="search-icon" />
    <input #field type="search" [value]="value()" (input)="set(field.value)" (blur)="onTouched()" (keydown.escape)="clear(field)"
           [placeholder]="placeholder()" [attr.aria-label]="label() ?? placeholder()" autocomplete="off" spellcheck="false" />
    @if (value()) {
      <button type="button" class="search-clear" (click)="clear(field)" aria-label="Effacer la recherche"><app-icon name="x" [size]="14" /></button>
    } @else {
      <kbd class="kbd search-kbd" aria-hidden="true">/</kbd>
    }
  `,
})
export class SearchInputComponent implements ControlValueAccessor {
  readonly placeholder = input('Rechercher');
  readonly label = input<string | null>(null);
  readonly value = signal('');
  private onChange: (v: string) => void = () => {};
  onTouched: () => void = () => {};

  writeValue(v: string | null): void {
    this.value.set(v ?? '');
  }
  registerOnChange(fn: (v: string) => void): void {
    this.onChange = fn;
  }
  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  set(v: string): void {
    this.value.set(v);
    this.onChange(v);
  }

  clear(field: HTMLInputElement): void {
    if (!this.value()) return;
    this.set('');
    field.focus();
  }

  /** "/" focuses the search field, unless the user is already typing somewhere. */
  onShortcut(event: KeyboardEvent): void {
    const target = event.target as HTMLElement;
    if (event.key !== '/' || target.closest('input, textarea, select, [contenteditable]')) return;
    const field = (event.currentTarget as Document).querySelector<HTMLInputElement>('app-search input');
    if (field) {
      event.preventDefault();
      field.focus();
    }
  }
}

/**
 * Makes a table readable on phones: copies each column header into a data-label attribute on
 * the matching cells, which the stylesheet turns into a stacked "label: value" card layout.
 * Re-labels automatically when rows change.
 */
@Directive({ selector: 'table[appResponsive]', host: { class: 'responsive' } })
export class ResponsiveTableDirective implements AfterViewInit, OnDestroy {
  private readonly el = inject<ElementRef<HTMLTableElement>>(ElementRef);
  private observer?: MutationObserver;

  ngAfterViewInit(): void {
    this.label();
    this.observer = new MutationObserver(() => this.label());
    this.observer.observe(this.el.nativeElement, { childList: true, subtree: true });
  }

  ngOnDestroy(): void {
    this.observer?.disconnect();
  }

  private label(): void {
    const table = this.el.nativeElement;
    const headers = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent?.trim() ?? '');
    table.querySelectorAll('tbody tr').forEach((row) => {
      Array.from(row.children).forEach((cell, i) => {
        const label = headers[i] ?? '';
        if (cell.getAttribute('data-label') !== label) cell.setAttribute('data-label', label);
      });
    });
  }
}

@Component({
  selector: 'app-empty',
  imports: [IconComponent],
  template: `
    <div class="empty">
      <div class="empty-icon"><app-icon [name]="icon()" [size]="20" /></div>
      <p class="empty-title">{{ title() }}</p>
      @if (hint()) {
        <p class="muted">{{ hint() }}</p>
      }
      <ng-content />
    </div>
  `,
})
export class EmptyStateComponent {
  readonly title = input('Aucun élément');
  readonly hint = input<string | null>(null);
  readonly icon = input<IconName>('inbox');
}

@Component({
  selector: 'app-skeleton',
  template: `<div class="skeleton" aria-busy="true" aria-label="Chargement">@for (i of rowsArray(); track i) { <div class="skeleton-row"></div> }</div>`,
})
export class SkeletonComponent {
  readonly rows = input(4);
  readonly rowsArray = computed(() => Array.from({ length: this.rows() }, (_, i) => i));
}

/** Tag input for skills: type then Enter or comma. */
@Component({
  selector: 'app-skills-input',
  imports: [FormsModule, IconComponent],
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => SkillsInputComponent), multi: true }],
  template: `
    <div class="chips-input" (click)="field.focus()">
      @for (s of skills(); track s) {
        <span class="chip">{{ s }}<button type="button" (click)="remove(s); $event.stopPropagation()" [attr.aria-label]="'Retirer ' + s"><app-icon name="x" [size]="12" [stroke]="2" /></button></span>
      }
      <input #field [(ngModel)]="draft" [ngModelOptions]="{ standalone: true }" (keydown)="onKey($event)" (blur)="commit()"
             [placeholder]="skills().length ? 'Ajouter…' : placeholder()" [disabled]="disabled()" [attr.aria-label]="placeholder()" />
    </div>
  `,
})
export class SkillsInputComponent implements ControlValueAccessor {
  readonly placeholder = input('Ajouter une compétence puis Entrée');
  readonly skills = signal<string[]>([]);
  readonly disabled = signal(false);
  draft = '';
  private onChange: (v: string[]) => void = () => {};
  private onTouched: () => void = () => {};

  writeValue(value: string[] | null): void {
    this.skills.set(value ?? []);
  }
  registerOnChange(fn: (v: string[]) => void): void {
    this.onChange = fn;
  }
  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }
  setDisabledState(disabled: boolean): void {
    this.disabled.set(disabled);
  }

  onKey(event: KeyboardEvent): void {
    if (event.key === 'Enter' || event.key === ',') {
      event.preventDefault();
      this.commit();
    } else if (event.key === 'Backspace' && !this.draft && this.skills().length) {
      this.remove(this.skills()[this.skills().length - 1]);
    }
  }

  commit(): void {
    const value = this.draft.trim().replace(/,$/, '');
    this.draft = '';
    this.onTouched();
    if (!value || this.skills().some((s) => s.toLowerCase() === value.toLowerCase())) return;
    this.skills.update((list) => [...list, value]);
    this.onChange(this.skills());
  }

  remove(skill: string): void {
    this.skills.update((list) => list.filter((s) => s !== skill));
    this.onChange(this.skills());
  }
}

/** Monthly bar chart with a value axis and hover tooltips. */
@Component({
  selector: 'app-bar-chart',
  imports: [EurPipe, EurShortPipe],
  template: `
    <div class="chart" role="img" [attr.aria-label]="ariaLabel()">
      <div class="chart-axis" aria-hidden="true">
        @for (t of ticks(); track $index) { <span>{{ t | eurShort }}</span> }
      </div>
      <div class="chart-grid" aria-hidden="true">@for (t of ticks(); track $index) { <span></span> }</div>
      <div class="bar-chart">
        @for (bar of bars(); track bar.label; let i = $index) {
          <div class="bar-col">
            <div class="bar-tip"><b>{{ bar.value | eur }}</b><small>{{ bar.label }}</small></div>
            <div class="bar-track"><div class="bar" [class.empty]="!bar.value" [style.height.%]="bar.pct" [style.animation-delay.ms]="i * 30"></div></div>
            <span class="bar-label">{{ bar.short }}</span>
          </div>
        }
      </div>
    </div>
  `,
})
export class BarChartComponent {
  readonly data = input<{ period: string; amount: number }[]>([]);
  readonly ariaLabel = input('Montants mensuels');

  /** A "nice" axis maximum (1, 2, 2.5 or 5 × 10^n). */
  private readonly max = computed(() => {
    const raw = Math.max(1, ...this.data().map((d) => d.amount));
    const exp = Math.pow(10, Math.floor(Math.log10(raw)));
    return ([1, 2, 2.5, 5, 10].find((m) => m * exp >= raw) ?? 10) * exp;
  });
  readonly ticks = computed(() => [1, 0.75, 0.5, 0.25, 0].map((f) => this.max() * f));
  readonly bars = computed(() =>
    this.data().map((d) => {
      const [y, m] = d.period.split('-').map(Number);
      const date = new Date(y, m - 1, 1);
      return {
        label: new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' }).format(date),
        short: new Intl.DateTimeFormat('fr-FR', { month: 'short' }).format(date).replace('.', ''),
        value: d.amount,
        pct: d.amount > 0 ? Math.max(2, (d.amount / this.max()) * 100) : 2,
      };
    }),
  );
}

export const SHARED = [
  EurPipe, EurShortPipe, FrDatePipe, PeriodPipe, AgoPipe, InitialsPipe, StatusBadgeComponent, ScoreComponent, StarsComponent,
  RatingInputComponent, PaginationComponent, EmptyStateComponent, SkeletonComponent, SkillsInputComponent, BarChartComponent, IconComponent,
  TableSkeletonComponent, SearchInputComponent, ResponsiveTableDirective,
] as const;
