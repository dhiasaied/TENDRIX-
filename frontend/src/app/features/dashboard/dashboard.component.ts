import { DecimalPipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { STATUS } from '../../core/labels';
import { Dashboard } from '../../core/models';
import { IconComponent, IconName } from '../../shared/icon.component';
import { BarChartComponent, EurPipe, InitialsPipe, SkeletonComponent, StarsComponent } from '../../shared/ui';

/** Icon and tone for each KPI key returned by /api/reports/dashboard. */
const KPI_STYLE: Record<string, [IconName, string]> = {
  openTenders: ['tender', 'tone-info'],
  proposalsToReview: ['proposal', 'tone-warning'],
  activeContracts: ['contract', 'tone-success'],
  invoicesToApprove: ['invoice', 'tone-warning'],
  spendYtd: ['euro', ''],
  committedValue: ['wallet', ''],
  averageDailyRate: ['coins', 'tone-accent'],
  pendingApproval: ['clock', 'tone-warning'],
  opportunities: ['opportunity', 'tone-info'],
  proposalsInProgress: ['proposal', ''],
  winRate: ['target', 'tone-success'],
  revenueYtd: ['euro', 'tone-success'],
  outstanding: ['wallet', 'tone-accent'],
  averageRating: ['star', 'tone-warning'],
  contractsToSign: ['pen-line', 'tone-warning'],
  buyers: ['building', 'tone-info'],
  suppliers: ['briefcase', 'tone-accent'],
  users: ['users', ''],
  publishedTenders: ['tender', 'tone-info'],
  proposals: ['proposal', ''],
  gmv: ['euro', 'tone-success'],
};

const ACTION_ICON: Record<string, IconName> = { tender: 'tender', proposal: 'proposal', contract: 'pen-line', invoice: 'invoice' };

@Component({
  selector: 'app-dashboard',
  imports: [RouterLink, DecimalPipe, EurPipe, InitialsPipe, BarChartComponent, StarsComponent, SkeletonComponent, IconComponent],
  template: `
    <div class="page">
      <header class="page-head">
        <div>
          <p class="eyebrow">{{ today }}</p>
          <h1>Bonjour {{ firstName() }}</h1>
          <p class="lead">{{ subtitle() }}</p>
        </div>
        <div class="head-actions">
          @if (auth.isClientSide()) {
            <a routerLink="/tenders/new" class="btn btn-primary"><app-icon name="plus" />Nouvel appel d'offres</a>
          } @else if (auth.isSupplier()) {
            <a routerLink="/tenders" class="btn btn-primary"><app-icon name="opportunity" />Voir les opportunités</a>
          }
        </div>
      </header>

      @if (data(); as d) {
        @if (d.actionItems.length) {
          <section class="todo" aria-label="À traiter">
            @for (item of d.actionItems; track item.label) {
              <button class="todo-item" (click)="go(item.link)">
                <span class="todo-icon"><app-icon [name]="actionIcon(item.type)" /></span>
                <span>{{ item.label }}</span>
                <app-icon name="arrow-right" class="arrow" />
              </button>
            }
          </section>
        }

        <section class="kpis" aria-label="Indicateurs clés">
          @for (k of d.kpis; track k.key) {
            <div class="kpi">
              <div class="kpi-top">
                <span class="kpi-label">{{ k.label }}</span>
                <span [class]="'kpi-icon ' + kpiTone(k.key)"><app-icon [name]="kpiIcon(k.key)" /></span>
              </div>
              <span class="kpi-value">
                @switch (k.format) {
                  @case ('currency') { {{ k.value | eur }} }
                  @case ('percent') { {{ k.value | number: '1.0-1' }}<small> %</small> }
                  @case ('rating') { {{ k.value ? (k.value | number: '1.1-1') : '—' }}<small> / 5</small> }
                  @default { {{ k.value | number }} }
                }
              </span>
            </div>
          }
        </section>

        <div class="grid-main">
          <section class="card">
            <div class="card-head">
              <div>
                <h2>{{ d.space === 'supplier' ? 'Chiffre d\\'affaires facturé' : 'Dépenses facturées' }}</h2>
                <span class="subtle">Montants HT validés ou payés, 12 derniers mois</span>
              </div>
              <span class="card-figure">{{ yearTotal() | eur }}</span>
            </div>
            <app-bar-chart [data]="d.monthly" />
          </section>

          <section class="card">
            <div class="card-head"><h2>{{ d.space === 'supplier' ? 'Principaux clients' : 'Principaux fournisseurs' }}</h2></div>
            @if (d.topPartners.length) {
              <ul class="rank">
                @for (p of d.topPartners; track p.id; let i = $index) {
                  <li>
                    <span class="org-mark sm">{{ p.name | initials }}</span>
                    <span class="rank-name">
                      {{ p.name }}
                      @if (p.rating) { <app-stars [value]="p.rating" [size]="12" /> } @else { <small class="subtle">Non évalué</small> }
                    </span>
                    <b>{{ p.amount | eur }}</b>
                  </li>
                }
              </ul>
            } @else {
              <p class="muted small">Aucune facturation validée pour le moment.</p>
            }
          </section>
        </div>

        <section class="breakdowns">
          @for (b of breakdowns(); track b.key) {
            <div class="card">
              <div class="card-head compact"><h2>{{ b.title }}</h2><span class="pill">{{ b.total }}</span></div>
              @if (b.total === 0) {
                <p class="muted small">Aucune donnée.</p>
              } @else {
                <div class="stack-bar" role="img" [attr.aria-label]="b.title + ' par statut'">
                  @for (s of b.items; track s.status) {
                    <span [class]="'seg tone-' + s.tone" [style.flex]="s.count" [title]="s.label + ' : ' + s.count"></span>
                  }
                </div>
                <ul class="legend">
                  @for (s of b.items; track s.status) {
                    <li><span [class]="'swatch tone-' + s.tone"></span>{{ s.label }} <b>{{ s.count }}</b></li>
                  }
                </ul>
              }
            </div>
          }
        </section>
      } @else {
        <app-skeleton [rows]="6" />
      }
    </div>
  `,
})
export class DashboardComponent implements OnInit {
  readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);

  readonly today = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());
  readonly data = signal<Dashboard | null>(null);
  readonly firstName = computed(() => this.auth.displayName().split(' ')[0]);
  readonly subtitle = computed(() =>
    this.auth.isAdmin()
      ? 'Vue d\'ensemble de l\'activité de la plateforme.'
      : this.auth.isSupplier()
        ? 'Vos opportunités, contrats et paiements en un coup d\'œil.'
        : 'Pilotez vos achats de prestations intellectuelles.',
  );
  readonly yearTotal = computed(() => (this.data()?.monthly ?? []).reduce((s, m) => s + m.amount, 0));

  readonly breakdowns = computed(() => {
    const titles: Record<string, [string, string]> = {
      tenders: ['Appels d\'offres', 'tender'],
      proposals: ['Propositions', 'proposal'],
      contracts: ['Contrats', 'contract'],
      invoices: ['Factures', 'invoice'],
    };
    return Object.entries(this.data()?.statusBreakdown ?? {}).map(([key, counts]) => {
      const [title, kind] = titles[key] ?? [key, key];
      const items = Object.entries(counts).map(([status, count]) => ({
        status,
        count,
        label: STATUS[kind]?.[status]?.[0] ?? status,
        tone: STATUS[kind]?.[status]?.[1] ?? 'neutral',
      }));
      return { key, title, items, total: items.reduce((s, i) => s + i.count, 0) };
    });
  });

  ngOnInit(): void {
    this.api.get<Dashboard>('reports/dashboard').subscribe((d) => this.data.set(d));
  }

  kpiIcon(key: string): IconName {
    return KPI_STYLE[key]?.[0] ?? 'gauge';
  }

  kpiTone(key: string): string {
    return KPI_STYLE[key]?.[1] ?? '';
  }

  actionIcon(type: string): IconName {
    return ACTION_ICON[type] ?? 'circle-alert';
  }

  go(link: string): void {
    this.router.navigateByUrl(link);
  }
}
