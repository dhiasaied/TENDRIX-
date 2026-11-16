import { Component, ElementRef, HostListener, computed, inject, input, output, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, debounceTime, forkJoin, of, switchMap } from 'rxjs';
import { catchError, map } from 'rxjs/operators';
import { ApiService } from '../core/api.service';
import { AuthService } from '../core/auth.service';
import { Contract, Invoice, Tender } from '../core/models';
import { ThemeService } from '../core/theme.service';
import { IconComponent, IconName } from '../shared/icon.component';

export interface PaletteItem {
  group: string;
  label: string;
  icon: IconName;
  meta?: string;
  run: () => void;
}

/**
 * ⌘K / Ctrl+K: jump anywhere, run an action, or search records across the user's tenant.
 */
@Component({
  selector: 'app-command-palette',
  imports: [FormsModule, IconComponent],
  template: `
    <div class="palette-backdrop" (click)="close.emit()">
      <div class="palette" role="dialog" aria-modal="true" aria-label="Recherche et commandes" (click)="$event.stopPropagation()">
        <div class="palette-input">
          <app-icon name="search" [size]="18" />
          <input #field [ngModel]="query()" (ngModelChange)="onQuery($event)" placeholder="Rechercher un AO, un contrat, une facture ou une page…"
                 (keydown)="onKey($event)" aria-label="Rechercher" role="combobox" aria-expanded="true" aria-controls="palette-list" />
          <span class="kbd">Échap</span>
        </div>
        <div class="palette-list" id="palette-list" role="listbox">
          @for (group of grouped(); track group.name) {
            <div class="palette-group">{{ group.name }}</div>
            @for (item of group.items; track item.label + item.meta) {
              <button class="palette-item" role="option" [class.active]="item === active()" [attr.aria-selected]="item === active()"
                      (mouseenter)="activeIndex.set(flat().indexOf(item))" (click)="select(item)">
                <app-icon [name]="item.icon" />
                <span>{{ item.label }}</span>
                @if (item.meta) { <span class="meta">{{ item.meta }}</span> }
              </button>
            }
          } @empty {
            <div class="palette-empty">{{ searching() ? 'Recherche…' : 'Aucun résultat pour « ' + query() + ' »' }}</div>
          }
        </div>
        <div class="palette-foot">
          <span><span class="kbd"><app-icon name="arrow-up" [size]="11" /></span><span class="kbd"><app-icon name="arrow-down" [size]="11" /></span> naviguer</span>
          <span><span class="kbd"><app-icon name="enter" [size]="11" /></span> ouvrir</span>
          <span><span class="kbd">Échap</span> fermer</span>
        </div>
      </div>
    </div>
  `,
})
export class CommandPaletteComponent {
  private readonly api = inject(ApiService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly theme = inject(ThemeService);

  readonly navigation = input<{ label: string; path: string; icon: IconName }[]>([]);
  readonly close = output<void>();
  private readonly field = viewChild<ElementRef<HTMLInputElement>>('field');

  readonly query = signal('');
  readonly searching = signal(false);
  readonly results = signal<PaletteItem[]>([]);
  readonly activeIndex = signal(0);
  private readonly search$ = new Subject<string>();

  readonly commands = computed<PaletteItem[]>(() => {
    const items: PaletteItem[] = this.navigation().map((n) => ({ group: 'Navigation', label: n.label, icon: n.icon, run: () => this.go(n.path) }));
    if (this.auth.isClientSide()) items.unshift({ group: 'Actions', label: 'Nouvel appel d\'offres', icon: 'plus', run: () => this.go('/tenders/new') });
    items.push(
      { group: 'Préférences', label: 'Thème clair', icon: 'sun', run: () => this.theme.set('light') },
      { group: 'Préférences', label: 'Thème sombre', icon: 'moon', run: () => this.theme.set('dark') },
      { group: 'Préférences', label: 'Thème du système', icon: 'monitor', run: () => this.theme.set('system') },
    );
    return items;
  });

  readonly flat = computed(() => {
    const q = normalize(this.query());
    const commands = q ? this.commands().filter((c) => normalize(c.label).includes(q)) : this.commands();
    return [...this.results(), ...commands];
  });
  readonly active = computed(() => this.flat()[Math.min(this.activeIndex(), this.flat().length - 1)]);
  readonly grouped = computed(() => {
    const groups = new Map<string, PaletteItem[]>();
    for (const item of this.flat()) groups.set(item.group, [...(groups.get(item.group) ?? []), item]);
    return [...groups].map(([name, items]) => ({ name, items }));
  });

  constructor() {
    this.search$
      .pipe(
        debounceTime(200),
        switchMap((q) => (q.length < 2 ? of([]) : this.searchRecords(q))),
      )
      .subscribe((items) => {
        this.results.set(items);
        this.searching.set(false);
      });
    queueMicrotask(() => this.field()?.nativeElement.focus());
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.close.emit();
  }

  onQuery(value: string): void {
    this.query.set(value);
    this.activeIndex.set(0);
    this.searching.set(value.trim().length >= 2);
    this.search$.next(value.trim());
  }

  onKey(event: KeyboardEvent): void {
    const count = this.flat().length;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      this.activeIndex.set((this.activeIndex() + 1) % Math.max(1, count));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      this.activeIndex.set((this.activeIndex() - 1 + count) % Math.max(1, count));
    } else if (event.key === 'Enter' && this.active()) {
      event.preventDefault();
      this.select(this.active()!);
    }
  }

  select(item: PaletteItem): void {
    item.run();
    this.close.emit();
  }

  private go(path: string): void {
    this.router.navigateByUrl(path);
  }

  private searchRecords(q: string) {
    const market = this.auth.isClientSide() || this.auth.isSupplier();
    const billing = this.auth.isBuyer() || this.auth.isSupplier();
    const safe = <T>(obs: ReturnType<ApiService['list']>) => obs.pipe(map((c) => c.member as T[]), catchError(() => of([] as T[])));
    return forkJoin({
      tenders: market ? safe<Tender>(this.api.list<Tender>('tenders', { title: q, itemsPerPage: 5 })) : of([] as Tender[]),
      contracts: market ? safe<Contract>(this.api.list<Contract>('contracts', { consultantName: q, itemsPerPage: 4 })) : of([] as Contract[]),
      invoices: billing ? safe<Invoice>(this.api.list<Invoice>('invoices', { number: q, itemsPerPage: 4 })) : of([] as Invoice[]),
    }).pipe(
      map(({ tenders, contracts, invoices }) => [
        ...tenders.map((t): PaletteItem => ({ group: 'Appels d\'offres', label: t.title, meta: t.reference, icon: 'tender', run: () => this.go(`/tenders/${t.id}`) })),
        ...contracts.map((c): PaletteItem => ({ group: 'Contrats', label: `${c.consultantName} — ${c.title}`, meta: c.reference, icon: 'contract', run: () => this.go(`/contracts/${c.id}`) })),
        ...invoices.map((i): PaletteItem => ({ group: 'Factures', label: `${i.contract.consultantName} · ${i.period}`, meta: i.number, icon: 'invoice', run: () => this.go(`/invoices/${i.id}`) })),
      ]),
    );
  }
}

function normalize(s: string): string {
  return s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim();
}
