import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Subject, debounceTime } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { STATUS } from '../../core/labels';
import { Contract } from '../../core/models';
import { IconComponent } from '../../shared/icon.component';
import { EmptyStateComponent, EurPipe, FrDatePipe, PaginationComponent, ResponsiveTableDirective, SearchInputComponent, TableSkeletonComponent, StatusBadgeComponent } from '../../shared/ui';

@Component({
  selector: 'app-contract-list',
  imports: [FormsModule, RouterLink, StatusBadgeComponent, PaginationComponent, ResponsiveTableDirective, SearchInputComponent, EmptyStateComponent, TableSkeletonComponent, EurPipe, FrDatePipe, IconComponent],
  template: `
    <div class="page">
      <header class="page-head">
        <div>
          <h1>Contrats</h1>
          <p class="lead">Missions contractualisées, signatures et consommation des jours.</p>
        </div>
      </header>
      <section class="data-card">
      <div class="data-toolbar">
        <app-search placeholder="Nom du consultant" label="Rechercher un consultant" [(ngModel)]="search" (ngModelChange)="search$.next()" />
        <select class="filter" [class.active]="status" [(ngModel)]="status" (ngModelChange)="reload(1)" aria-label="Filtrer par statut">
          <option value="">Tous les statuts</option>
          @for (s of statuses; track s[0]) { <option [value]="s[0]">{{ s[1][0] }}</option> }
        </select>
        <span class="spacer"></span>
        @if (search || status) { <button type="button" class="btn-reset" (click)="search = ''; status = ''; reload(1)"><app-icon name="reset" [size]="14" />Réinitialiser</button> }
        <span class="result-count" aria-live="polite"><b>{{ total() }}</b> résultat{{ total() > 1 ? 's' : '' }}</span>
      </div>
      @if (loading()) {
        <app-table-skeleton [cols]="8" />
      } @else if (!items().length) {
        <app-empty icon="contract" title="Aucun contrat" hint="Les contrats sont générés automatiquement lorsqu'une proposition est retenue." />
      } @else {
        <div class="table-wrap">
            <table class="table" appResponsive>
              <thead><tr><th>Référence</th><th>Mission</th><th>{{ auth.isSupplier() ? 'Client' : 'Fournisseur' }}</th><th>Période</th><th class="num">Valeur</th><th>Consommation</th><th>Statut</th><th></th></tr></thead>
              <tbody>
                @for (c of items(); track c.id) {
                  <tr class="clickable" tabindex="0" [routerLink]="['/contracts', c.id]" (keydown.enter)="router.navigate(['/contracts', c.id])">
                    <td><span class="ref">{{ c.reference }}</span></td>
                    <td><span class="cell-main">{{ c.consultantName }}</span><span class="cell-sub">{{ c.title }}</span></td>
                    <td>{{ auth.isSupplier() ? c.buyer.name : c.supplier.name }}</td>
                    <td>{{ c.startDate | frDate }}<span class="cell-sub">au {{ c.endDate | frDate }}</span></td>
                    <td class="num">{{ c.totalValue | eur }}</td>
                    <td>
                      <span class="meter" [class.success]="c.consumptionRate >= 100"><span [style.width.%]="c.consumptionRate"></span></span>
                      <span class="cell-sub">{{ c.invoicedDays }} / {{ c.plannedDays }} j</span>
                    </td>
                    <td><app-status kind="contract" [status]="c.status" /></td>
                    <td class="cell-arrow"><app-icon name="chevron-right" /></td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        <div class="data-foot"><app-pagination [page]="page()" [total]="total()" (pageChange)="reload($event)" /></div>
      }
      </section>
    </div>
  `,
})
export class ContractListComponent implements OnInit {
  readonly auth = inject(AuthService);
  readonly router = inject(Router);
  private readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);
  readonly items = signal<Contract[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly loading = signal(true);
  readonly statuses = Object.entries(STATUS['contract']);
  readonly search$ = new Subject<void>();
  search = '';
  status = '';

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.reload(1));
  }

  ngOnInit(): void {
    this.status = this.route.snapshot.queryParamMap.get('status') ?? '';
    this.reload(1);
  }

  reload(page: number): void {
    this.page.set(page);
    this.loading.set(true);
    this.api.list<Contract>('contracts', { page, status: this.status, consultantName: this.search }).subscribe((c) => {
      this.items.set(c.member);
      this.total.set(c.totalItems);
      this.loading.set(false);
    });
  }
}
