import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Subject, debounceTime } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { Organization } from '../../core/models';
import { IconComponent } from '../../shared/icon.component';
import { EmptyStateComponent, InitialsPipe, PaginationComponent, SearchInputComponent, SkeletonComponent, StarsComponent } from '../../shared/ui';

@Component({
  selector: 'app-directory',
  imports: [FormsModule, StarsComponent, EmptyStateComponent, SkeletonComponent, PaginationComponent, SearchInputComponent, IconComponent, InitialsPipe],
  template: `
    <div class="page">
      <header class="page-head">
        <div>
          <h1>Annuaire des fournisseurs</h1>
          <p class="lead">Les prestataires référencés sur la plateforme et leurs expertises.</p>
        </div>
      </header>
      <div class="toolbar">
        <app-search placeholder="Nom du fournisseur" label="Rechercher un fournisseur" [(ngModel)]="search" (ngModelChange)="search$.next()" />
        <div class="input-icon"><app-icon name="pin" /><input type="search" placeholder="Ville" [(ngModel)]="city" (ngModelChange)="search$.next()" aria-label="Filtrer par ville" /></div>
      </div>
      @if (loading()) {
        <app-skeleton />
      } @else if (!items().length) {
        <app-empty icon="building" title="Aucun fournisseur trouvé" hint="Essayez un autre nom ou une autre ville." />
      } @else {
        <div class="scorecards">
          @for (o of items(); track o.id; let i = $index) {
            <article class="card tender-card" [style.animation-delay.ms]="i * 30">
              <div class="tc-top">
                <div class="org-id">
                  <span class="org-mark">{{ o.name | initials }}</span>
                  <div><h3>{{ o.name }}</h3><small class="subtle">{{ subtitle(o) }}</small></div>
                </div>
                @if (o.averageRating) { <app-stars [value]="o.averageRating" [size]="13" /> }
              </div>
              @if (o.description) { <p class="muted small">{{ o.description }}</p> }
              <div class="chips">@for (s of o.skills; track s) { <span class="chip">{{ s }}</span> }</div>
              @if (o.website) {
                <div class="tc-foot"><a [href]="o.website" target="_blank" rel="noopener noreferrer"><app-icon name="globe" [size]="14" />{{ o.website }}</a></div>
              }
            </article>
          }
        </div>
        <app-pagination [page]="page()" [total]="total()" (pageChange)="reload($event)" />
      }
    </div>
  `,
})
export class DirectoryComponent implements OnInit {
  private readonly api = inject(ApiService);
  readonly items = signal<Organization[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly loading = signal(true);
  readonly search$ = new Subject<void>();
  search = '';
  city = '';

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.reload(1));
  }

  ngOnInit(): void {
    this.reload(1);
  }

  subtitle(o: Organization): string {
    return [o.sector, o.city].filter(Boolean).join(' · ');
  }

  reload(page: number): void {
    this.page.set(page);
    this.loading.set(true);
    this.api.list<Organization>('organizations', { type: 'supplier', page, name: this.search, city: this.city, 'order[averageRating]': 'desc' }).subscribe((c) => {
      this.items.set(c.member);
      this.total.set(c.totalItems);
      this.loading.set(false);
    });
  }
}
