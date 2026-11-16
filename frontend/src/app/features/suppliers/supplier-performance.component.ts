import { DecimalPipe } from '@angular/common';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { ApiService } from '../../core/api.service';
import { AuthService } from '../../core/auth.service';
import { SupplierPerformance } from '../../core/models';
import { EmptyStateComponent, EurPipe, InitialsPipe, SkeletonComponent, StarsComponent } from '../../shared/ui';

type SortKey = 'averageRating' | 'totalBilled' | 'winRate' | 'averageMatchScore';

@Component({
  selector: 'app-supplier-performance',
  imports: [DecimalPipe, EurPipe, InitialsPipe, StarsComponent, EmptyStateComponent, SkeletonComponent],
  template: `
    <div class="page">
      <header class="page-head">
        <div>
          <h1>{{ auth.isSupplier() ? 'Ma performance' : 'Performance fournisseurs' }}</h1>
          <p class="lead">{{ auth.isSupplier() ? 'Vos indicateurs tels que vos clients les perçoivent.' : 'Indicateurs calculés sur votre relation avec chaque fournisseur ; notes consolidées sur la plateforme.' }}</p>
        </div>
        @if (!auth.isSupplier() && rows().length > 1) {
          <div class="segmented" role="radiogroup" aria-label="Trier par">
            @for (s of sorts; track s.value) {
              <button role="radio" [attr.aria-checked]="sortKey() === s.value" [class.on]="sortKey() === s.value" (click)="sortKey.set(s.value)">{{ s.label }}</button>
            }
          </div>
        }
      </header>

      @if (loading()) {
        <app-skeleton />
      } @else if (!sorted().length) {
        <app-empty icon="analytics" title="Aucune donnée de performance" hint="Les indicateurs apparaissent après les premières propositions et les premiers contrats." />
      } @else {
        <div class="scorecards">
          @for (s of sorted(); track s.supplierId; let i = $index) {
            <article class="card scorecard tender-card" [style.animation-delay.ms]="i * 40">
              <header>
                <div class="org-id">
                  <span class="org-mark">{{ s.name | initials }}</span>
                  <div><h3>{{ s.name }}</h3>@if (s.city) { <small class="subtle">{{ s.city }}</small> }</div>
                </div>
                <div class="rating-big">
                  @if (s.averageRating) {
                    <b>{{ s.averageRating | number: '1.1-1' }}</b>
                    <app-stars [value]="s.averageRating" [showValue]="false" [size]="13" />
                    <small class="subtle">{{ s.evaluationsCount }} évaluation{{ s.evaluationsCount > 1 ? 's' : '' }}</small>
                  } @else {
                    <span class="badge plain tone-neutral">Non évalué</span>
                  }
                </div>
              </header>
              <div class="sc-metrics">
                <div><small>Propositions</small><b>{{ s.proposalsCount }}</b></div>
                <div><small>Taux de succès</small><b>{{ s.winRate | number: '1.0-1' }} %</b></div>
                <div><small>Adéquation moy.</small><b>{{ s.averageMatchScore !== null && s.averageMatchScore !== undefined ? (s.averageMatchScore | number: '1.0-0') : '—' }}</b></div>
                <div><small>TJM moyen</small><b>{{ s.averageDailyRate | eur }}</b></div>
                <div><small>Contrats</small><b>{{ s.contractsCount }} <span class="subtle small">· {{ s.activeContracts }} actif{{ s.activeContracts > 1 ? 's' : '' }}</span></b></div>
                <div><small>Facturé</small><b>{{ s.totalBilled | eur }}</b></div>
              </div>
              @if (s.evaluationsCount) {
                <ul class="criteria">
                  @for (c of criteria; track c.key) {
                    <li>
                      <span>{{ c.label }}</span>
                      <span class="meter"><span [style.width.%]="(s.ratingDetails[c.key] ?? 0) * 20"></span></span>
                      <b>{{ s.ratingDetails[c.key] | number: '1.1-1' }}</b>
                    </li>
                  }
                </ul>
              }
            </article>
          }
        </div>
      }
    </div>
  `,
})
export class SupplierPerformanceComponent implements OnInit {
  readonly auth = inject(AuthService);
  private readonly api = inject(ApiService);
  readonly rows = signal<SupplierPerformance[]>([]);
  readonly loading = signal(true);
  readonly sortKey = signal<SortKey>('averageRating');
  readonly sorts: { value: SortKey; label: string }[] = [
    { value: 'averageRating', label: 'Note' },
    { value: 'totalBilled', label: 'Facturé' },
    { value: 'winRate', label: 'Succès' },
    { value: 'averageMatchScore', label: 'Adéquation' },
  ];
  readonly criteria: { key: 'quality' | 'reactivity' | 'communication' | 'deadlines'; label: string }[] = [
    { key: 'quality', label: 'Qualité' },
    { key: 'reactivity', label: 'Réactivité' },
    { key: 'communication', label: 'Communication' },
    { key: 'deadlines', label: 'Délais' },
  ];

  readonly sorted = computed(() => {
    const key = this.sortKey();
    return [...this.rows()].sort((a, b) => Number(b[key] ?? -1) - Number(a[key] ?? -1));
  });

  ngOnInit(): void {
    this.api.get<SupplierPerformance[] | { member: SupplierPerformance[] }>('reports/suppliers').subscribe((r) => {
      this.rows.set(Array.isArray(r) ? r : r.member);
      this.loading.set(false);
    });
  }
}
