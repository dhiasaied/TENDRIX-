import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Subject, debounceTime } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { STATUS } from '../../core/labels';
import { Invoice } from '../../core/models';
import { IconComponent } from '../../shared/icon.component';
import { EmptyStateComponent, EurPipe, FrDatePipe, PaginationComponent, ResponsiveTableDirective, SearchInputComponent, PeriodPipe, TableSkeletonComponent, StatusBadgeComponent } from '../../shared/ui';

@Component({
  selector: 'app-invoice-list',
  imports: [FormsModule, RouterLink, StatusBadgeComponent, PaginationComponent, ResponsiveTableDirective, SearchInputComponent, EmptyStateComponent, TableSkeletonComponent, EurPipe, FrDatePipe, PeriodPipe, IconComponent],
  template: `
    <div class="page">
      <header class="page-head">
        <div>
          <h1>Factures</h1>
          <p class="lead">{{ auth.isSupplier() ? 'Facturez vos jours réalisés et suivez vos paiements.' : 'Validez et réglez les factures de vos fournisseurs.' }}</p>
        </div>
      </header>
      <section class="data-card">
      <div class="data-toolbar">
        <app-search placeholder="Numéro de facture" label="Rechercher une facture" [(ngModel)]="search" (ngModelChange)="search$.next()" />
        <div class="segmented" role="radiogroup" aria-label="Filtrer par statut">
          <button role="radio" [attr.aria-checked]="!status" [class.on]="!status" (click)="setStatus('')">Toutes</button>
          @for (s of statuses(); track s[0]) {
            <button role="radio" [attr.aria-checked]="status === s[0]" [class.on]="status === s[0]" (click)="setStatus(s[0])">{{ s[1][0] }}</button>
          }
        </div>
        <span class="spacer"></span>
        @if (search || status) { <button type="button" class="btn-reset" (click)="search = ''; setStatus('')"><app-icon name="reset" [size]="14" />Réinitialiser</button> }
        <span class="result-count" aria-live="polite"><b>{{ total() }}</b> résultat{{ total() > 1 ? 's' : '' }}</span>
      </div>
      @if (loading()) {
        <app-table-skeleton [cols]="8" />
      } @else if (!items().length) {
        <app-empty icon="invoice" title="Aucune facture" [hint]="auth.isSupplier() ? 'Créez une facture depuis un contrat actif.' : 'Les factures envoyées par vos fournisseurs apparaîtront ici.'" />
      } @else {
        <div class="table-wrap">
            <table class="table" appResponsive>
              <thead><tr><th>N°</th><th>Contrat</th><th>{{ auth.isSupplier() ? 'Client' : 'Fournisseur' }}</th><th>Période</th><th class="num">Montant TTC</th><th>Échéance</th><th>Statut</th><th></th></tr></thead>
              <tbody>
                @for (i of items(); track i.id) {
                  <tr class="clickable" tabindex="0" [routerLink]="['/invoices', i.id]" (keydown.enter)="router.navigate(['/invoices', i.id])">
                    <td><span class="ref">{{ i.number }}</span></td>
                    <td><span class="ref">{{ i.contract.reference }}</span><span class="cell-sub">{{ i.contract.consultantName }}</span></td>
                    <td>{{ auth.isSupplier() ? i.buyer.name : i.supplier.name }}</td>
                    <td>{{ i.period | period }}</td>
                    <td class="num"><span class="cell-main">{{ i.amountInclTax | eur: true }}</span></td>
                    <td [class.over]="i.overdue">{{ i.dueDate | frDate }}@if (i.overdue) { <span class="cell-sub">En retard</span> }</td>
                    <td><app-status kind="invoice" [status]="i.status" /></td>
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
export class InvoiceListComponent implements OnInit {
  readonly auth = inject(AuthService);
  readonly router = inject(Router);
  private readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);
  readonly items = signal<Invoice[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly loading = signal(true);
  readonly statuses = computed(() => Object.entries(STATUS['invoice']).filter(([s]) => this.auth.isSupplier() || s !== 'draft'));
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

  setStatus(status: string): void {
    this.status = status;
    this.reload(1);
  }

  reload(page: number): void {
    this.page.set(page);
    this.loading.set(true);
    this.api.list<Invoice>('invoices', { page, status: this.status, number: this.search }).subscribe((c) => {
      this.items.set(c.member);
      this.total.set(c.totalItems);
      this.loading.set(false);
    });
  }
}
