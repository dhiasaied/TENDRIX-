import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Subject, debounceTime } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { STATUS } from '../../core/labels';
import { Proposal } from '../../core/models';
import { IconComponent } from '../../shared/icon.component';
import { AgoPipe, EmptyStateComponent, EurPipe, PaginationComponent, ResponsiveTableDirective, SearchInputComponent, ScoreComponent, TableSkeletonComponent, StatusBadgeComponent } from '../../shared/ui';

@Component({
  selector: 'app-proposal-list',
  imports: [FormsModule, RouterLink, StatusBadgeComponent, ScoreComponent, PaginationComponent, ResponsiveTableDirective, SearchInputComponent, EmptyStateComponent, TableSkeletonComponent, EurPipe, AgoPipe, IconComponent],
  template: `
    <div class="page">
      <header class="page-head">
        <div>
          <h1>Propositions</h1>
          <p class="lead">{{ auth.isSupplier() ? 'Les consultants que vous avez proposés et leur avancement.' : 'Profils reçus sur vos appels d\\'offres, notés par l\\'agent de matching.' }}</p>
        </div>
      </header>

      <section class="data-card">
      <div class="data-toolbar">
        <app-search placeholder="Nom du candidat" label="Rechercher un candidat" [(ngModel)]="search" (ngModelChange)="search$.next()" />
        <select class="filter" [class.active]="status" [(ngModel)]="status" (ngModelChange)="reload(1)" aria-label="Filtrer par statut">
          <option value="">Tous les statuts</option>
          @for (s of statuses; track s[0]) { <option [value]="s[0]">{{ s[1][0] }}</option> }
        </select>
        <div class="segmented" role="radiogroup" aria-label="Trier">
          @for (o of orders; track o.value) {
            @if (o.value !== 'matchScore' || !auth.isSupplier()) {
            <button role="radio" [attr.aria-checked]="order === o.value" [class.on]="order === o.value" (click)="order = o.value; reload(1)">{{ o.label }}</button>
            }
          }
        </div>
        <span class="spacer"></span>
        @if (search || status) { <button type="button" class="btn-reset" (click)="search = ''; status = ''; reload(1)"><app-icon name="reset" [size]="14" />Réinitialiser</button> }
        <span class="result-count" aria-live="polite"><b>{{ total() }}</b> résultat{{ total() > 1 ? 's' : '' }}</span>
      </div>
      @if (loading()) {
        <app-table-skeleton [cols]="8" />
      } @else if (!items().length) {
        <app-empty icon="proposal" title="Aucune proposition" [hint]="auth.isSupplier() ? 'Répondez à une opportunité pour proposer un consultant.' : 'Les propositions arrivent ici dès que les fournisseurs répondent.'" />
      } @else {
        <div class="table-wrap">
            <table class="table" appResponsive>
              <thead><tr>@if (!auth.isSupplier()) { <th>Adéquation</th> }<th>Candidat</th><th>Appel d'offres</th>@if (!auth.isSupplier()) { <th>Fournisseur</th> }<th class="num">TJM</th><th>Reçue</th><th>Statut</th><th></th></tr></thead>
              <tbody>
                @for (p of items(); track p.id) {
                  <tr class="clickable" tabindex="0" [routerLink]="['/proposals', p.id]" (keydown.enter)="router.navigate(['/proposals', p.id])">
                    @if (!auth.isSupplier()) { <td><app-score [value]="p.matchScore" /></td> }
                    <td><span class="cell-main">{{ p.candidateName }}</span><span class="cell-sub">{{ p.candidateTitle }} · {{ p.experienceYears }} ans</span></td>
                    <td><span class="ref">{{ p.tender.reference }}</span><span class="cell-sub">{{ p.tender.title }}</span></td>
                    @if (!auth.isSupplier()) { <td>{{ p.supplier.name }}</td> }
                    <td class="num">{{ p.dailyRate | eur }}</td>
                    <td class="subtle">{{ p.createdAt | ago }}</td>
                    <td><app-status kind="proposal" [status]="p.status" /></td>
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
export class ProposalListComponent implements OnInit {
  readonly auth = inject(AuthService);
  readonly router = inject(Router);
  private readonly api = inject(ApiService);
  readonly items = signal<Proposal[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly loading = signal(true);
  readonly statuses = Object.entries(STATUS['proposal']);
  readonly orders = [
    { value: 'createdAt', label: 'Récentes' },
    { value: 'matchScore', label: 'Adéquation' },
    { value: 'dailyRate', label: 'TJM' },
  ];
  readonly search$ = new Subject<void>();
  search = '';
  status = '';
  order = 'createdAt';

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.reload(1));
  }

  ngOnInit(): void {
    this.reload(1);
  }

  reload(page: number): void {
    this.page.set(page);
    this.loading.set(true);
    const direction = this.order === 'dailyRate' ? 'asc' : 'desc';
    this.api
      .list<Proposal>('proposals', { page, status: this.status, candidateName: this.search, [`order[${this.order}]`]: direction })
      .subscribe((c) => {
        this.items.set(c.member);
        this.total.set(c.totalItems);
        this.loading.set(false);
      });
  }
}
