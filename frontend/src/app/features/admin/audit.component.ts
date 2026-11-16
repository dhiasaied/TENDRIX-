import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Subject, debounceTime } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuditLog } from '../../core/models';
import { IconComponent, IconName } from '../../shared/icon.component';
import { EmptyStateComponent, FrDatePipe, PaginationComponent, ResponsiveTableDirective, SearchInputComponent, TableSkeletonComponent } from '../../shared/ui';

const ENTITY_ICON: Record<string, IconName> = { tender: 'tender', proposal: 'proposal', contract: 'contract', invoice: 'invoice', user: 'user' };

@Component({
  selector: 'app-admin-audit',
  imports: [FormsModule, FrDatePipe, PaginationComponent, ResponsiveTableDirective, SearchInputComponent, TableSkeletonComponent, EmptyStateComponent, IconComponent],
  template: `
    <div class="page">
      <header class="page-head">
        <div><h1>Journal d'audit</h1><p class="lead">Historique immuable des connexions et des actions métier.</p></div>
      </header>
      <section class="data-card">
      <div class="data-toolbar">
        <app-search placeholder="E-mail de l'utilisateur" label="Filtrer par utilisateur" [(ngModel)]="actor" (ngModelChange)="search$.next()" />
        <select class="filter" [class.active]="entityType" [(ngModel)]="entityType" (ngModelChange)="reload(1)" aria-label="Filtrer par objet">
          <option value="">Tous les objets</option>
          <option value="tender">Appels d'offres</option><option value="proposal">Propositions</option>
          <option value="contract">Contrats</option><option value="invoice">Factures</option><option value="user">Utilisateurs</option>
        </select>
        <span class="spacer"></span>
        @if (actor || entityType) { <button type="button" class="btn-reset" (click)="actor = ''; entityType = ''; reload(1)"><app-icon name="reset" [size]="14" />Réinitialiser</button> }
        <span class="result-count" aria-live="polite"><b>{{ total() }}</b> résultat{{ total() > 1 ? 's' : '' }}</span>
      </div>
      @if (loading()) {
        <app-table-skeleton [cols]="5" />
      } @else if (!items().length) {
        <app-empty icon="history" title="Aucun événement" hint="Aucune action ne correspond à ces filtres." />
      } @else {
        <div class="table-wrap">
            <table class="table" appResponsive>
              <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Objet</th><th>Détails</th></tr></thead>
              <tbody>
                @for (l of items(); track l.id) {
                  <tr>
                    <td class="subtle">{{ l.createdAt | frDate: true }}</td>
                    <td>{{ l.actorEmail ?? 'Système' }}</td>
                    <td><code>{{ l.action }}</code></td>
                    <td><span class="title-row"><app-icon [name]="entityIcon(l.entityType)" class="subtle" />{{ l.entityType }} #{{ l.entityId }}</span></td>
                    <td class="small muted">{{ details(l) }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        <div class="data-foot"><app-pagination [page]="page()" [total]="total()" [perPage]="50" (pageChange)="reload($event)" /></div>
      }
      </section>
    </div>
  `,
})
export class AdminAuditComponent implements OnInit {
  private readonly api = inject(ApiService);
  readonly items = signal<AuditLog[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly loading = signal(true);
  readonly search$ = new Subject<void>();
  actor = '';
  entityType = '';

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.reload(1));
  }

  ngOnInit(): void {
    this.reload(1);
  }

  entityIcon(type: string): IconName {
    return ENTITY_ICON[type] ?? 'layers';
  }

  details(l: AuditLog): string {
    const p = l.payload as Record<string, unknown>;
    const parts: string[] = [];
    if (p['from'] && p['to']) parts.push(`${p['from']} → ${p['to']}`);
    if (p['matchScore'] !== undefined) parts.push(`score ${p['matchScore']}`);
    if (p['comment']) parts.push(`« ${p['comment']} »`);
    return parts.join(' · ') || '—';
  }

  reload(page: number): void {
    this.page.set(page);
    this.loading.set(true);
    this.api.list<AuditLog>('audit_logs', { page, actorEmail: this.actor, entityType: this.entityType }).subscribe((c) => {
      this.items.set(c.member);
      this.total.set(c.totalItems);
      this.loading.set(false);
    });
  }
}
