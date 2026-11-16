import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Subject, debounceTime } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { REMOTE, SENIORITY, STATUS } from '../../core/labels';
import { Tender } from '../../core/models';
import { IconComponent } from '../../shared/icon.component';
import { AgoPipe, EmptyStateComponent, EurPipe, FrDatePipe, PaginationComponent, SearchInputComponent, SkeletonComponent, StatusBadgeComponent } from '../../shared/ui';

@Component({
  selector: 'app-tender-list',
  imports: [FormsModule, RouterLink, StatusBadgeComponent, PaginationComponent, SearchInputComponent, EmptyStateComponent, SkeletonComponent, EurPipe, FrDatePipe, AgoPipe, IconComponent],
  template: `
    <div class="page">
      <header class="page-head">
        <div>
          <h1>{{ auth.isSupplier() ? 'Opportunités' : 'Appels d\\'offres' }}</h1>
          <p class="lead">{{ auth.isSupplier() ? 'Consultations ouvertes à votre entreprise et celles auxquelles vous avez répondu.' : 'Les besoins en prestations de votre organisation, du brouillon à l\\'attribution.' }}</p>
        </div>
        @if (auth.isClientSide()) {
          <a routerLink="/tenders/new" class="btn btn-primary"><app-icon name="plus" />Nouvel appel d'offres</a>
        }
      </header>

      <div class="toolbar">
        <app-search placeholder="Rechercher un intitulé" label="Rechercher un intitulé" [(ngModel)]="search" (ngModelChange)="search$.next()" />
        <select class="filter" [class.active]="status" [(ngModel)]="status" (ngModelChange)="reload(1)" aria-label="Filtrer par statut">
          <option value="">Tous les statuts</option>
          @for (s of statuses; track s[0]) { <option [value]="s[0]">{{ s[1][0] }}</option> }
        </select>
        <select class="filter" [class.active]="seniority" [(ngModel)]="seniority" (ngModelChange)="reload(1)" aria-label="Filtrer par séniorité">
          <option value="">Toute séniorité</option>
          @for (s of seniorities; track s[0]) { <option [value]="s[0]">{{ s[1] }}</option> }
        </select>
        <span class="spacer"></span>
        @if (!loading()) { <span class="result-count">{{ total() }} résultat{{ total() > 1 ? 's' : '' }}</span> }
      </div>

      @if (loading()) {
        <app-skeleton />
      } @else if (!items().length) {
        <app-empty icon="tender" [title]="hasFilters() ? 'Aucun résultat' : (auth.isSupplier() ? 'Aucune opportunité pour le moment' : 'Aucun appel d\\'offres')"
                   [hint]="hasFilters() ? 'Essayez d\\'élargir vos filtres.' : (auth.isSupplier() ? 'Vous serez notifié dès qu\\'une consultation correspond à vos expertises.' : 'Créez votre premier appel d\\'offres pour solliciter les fournisseurs.')">
          @if (auth.isClientSide() && !hasFilters()) { <a routerLink="/tenders/new" class="btn btn-primary"><app-icon name="plus" />Nouvel appel d'offres</a> }
        </app-empty>
      } @else {
        <div class="tender-cards">
          @for (t of items(); track t.id; let i = $index) {
            <a class="tender-card" [routerLink]="['/tenders', t.id]" [style.animation-delay.ms]="i * 30">
              <div class="tc-top">
                <span class="ref">{{ t.reference }}</span>
                <app-status kind="tender" [status]="t.status" />
              </div>
              <h3>{{ t.title }}</h3>
              <div class="tc-meta">
                @if (auth.isSupplier()) { <span><app-icon name="building" [size]="14" />{{ t.organization.name }}</span> }
                <span><app-icon name="award" [size]="14" />{{ seniorityLabel(t.seniority) }}</span>
                <span><app-icon name="pin" [size]="14" />{{ t.location }} · {{ remote(t.remotePolicy) }}</span>
                <span><app-icon name="clock" [size]="14" />{{ t.estimatedDays }} j</span>
              </div>
              <div class="chips">
                @for (s of t.skills.slice(0, 5); track s) { <span class="chip">{{ s }}</span> }
                @if (t.skills.length > 5) { <span class="chip">+{{ t.skills.length - 5 }}</span> }
              </div>
              <div class="tc-foot">
                <span>TJM max <b>{{ t.budgetMax | eur }}</b></span>
                @if (t.status === 'published') {
                  <span [class.urgent]="isSoon(t.deadline)"><app-icon name="calendar-clock" [size]="14" />{{ t.deadline | frDate }}</span>
                } @else {
                  <span class="subtle">Mis à jour {{ (t.updatedAt ?? t.createdAt) | ago }}</span>
                }
                @if (!auth.isSupplier()) { <span class="pill"><app-icon name="proposal" [size]="12" />{{ t.proposalsCount }}</span> }
              </div>
            </a>
          }
        </div>
        <app-pagination [page]="page()" [total]="total()" [perPage]="perPage" (pageChange)="reload($event)" />
      }
    </div>
  `,
})
export class TenderListComponent implements OnInit {
  readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  readonly items = signal<Tender[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly loading = signal(true);
  readonly perPage = 12;
  readonly statuses = Object.entries(STATUS['tender']);
  readonly seniorities = Object.entries(SENIORITY);
  readonly search$ = new Subject<void>();

  search = '';
  status = '';
  seniority = '';

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.reload(1));
  }

  ngOnInit(): void {
    const q = this.route.snapshot.queryParamMap;
    this.status = q.get('status') ?? '';
    this.reload(Number(q.get('page') ?? 1));
  }

  hasFilters(): boolean {
    return !!(this.search || this.status || this.seniority);
  }

  reload(page: number): void {
    this.page.set(page);
    this.loading.set(true);
    this.router.navigate([], { queryParams: { status: this.status || null, page: page > 1 ? page : null }, replaceUrl: true });
    this.api
      .list<Tender>('tenders', { page, itemsPerPage: this.perPage, status: this.status, seniority: this.seniority, title: this.search })
      .subscribe((c) => {
        this.items.set(c.member);
        this.total.set(c.totalItems);
        this.loading.set(false);
      });
  }

  seniorityLabel(s: string): string {
    return SENIORITY[s]?.split(' ')[0] ?? s;
  }

  remote(r: string): string {
    return REMOTE[r] ?? r;
  }

  isSoon(deadline: string): boolean {
    return new Date(deadline).getTime() - Date.now() < 3 * 86_400_000;
  }
}
