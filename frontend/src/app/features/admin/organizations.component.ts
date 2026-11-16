import { Component, HostListener, OnInit, inject, signal } from '@angular/core';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { Subject, debounceTime } from 'rxjs';
import { ApiService, apiErrorMessage } from '../../core/api.service';
import { Organization } from '../../core/models';
import { ToastService } from '../../core/toast.service';
import { IconComponent } from '../../shared/icon.component';
import { FrDatePipe, InitialsPipe, PaginationComponent, ResponsiveTableDirective, SearchInputComponent, TableSkeletonComponent, StarsComponent } from '../../shared/ui';

@Component({
  selector: 'app-admin-organizations',
  imports: [FormsModule, ReactiveFormsModule, FrDatePipe, PaginationComponent, ResponsiveTableDirective, SearchInputComponent, TableSkeletonComponent, StarsComponent, IconComponent, InitialsPipe],
  template: `
    <div class="page">
      <header class="page-head">
        <div><h1>Organisations</h1><p class="lead">Clients acheteurs et fournisseurs référencés sur la plateforme.</p></div>
        <button class="btn btn-primary" (click)="creating.set(true)"><app-icon name="plus" />Nouvelle organisation</button>
      </header>
      <section class="data-card">
      <div class="data-toolbar">
        <app-search placeholder="Nom de l'organisation" label="Rechercher" [(ngModel)]="search" (ngModelChange)="search$.next()" />
        <div class="segmented" role="radiogroup" aria-label="Type">
          @for (t of types; track t.value) {
            <button role="radio" [attr.aria-checked]="type === t.value" [class.on]="type === t.value" (click)="type = t.value; reload(1)">{{ t.label }}</button>
          }
        </div>
        <span class="spacer"></span>
        @if (search || type) { <button type="button" class="btn-reset" (click)="search = ''; type = ''; reload(1)"><app-icon name="reset" [size]="14" />Réinitialiser</button> }
        <span class="result-count" aria-live="polite"><b>{{ total() }}</b> résultat{{ total() > 1 ? 's' : '' }}</span>
      </div>
      @if (loading()) {
        <app-table-skeleton [cols]="7" />
      } @else {
        <div class="table-wrap">
            <table class="table" appResponsive>
              <thead><tr><th>Organisation</th><th>Type</th><th>Ville</th><th>Note</th><th>Créée le</th><th>Statut</th><th></th></tr></thead>
              <tbody>
                @for (o of items(); track o.id) {
                  <tr>
                    <td><div class="org-id"><span class="org-mark sm">{{ o.name | initials }}</span><div><span class="cell-main">{{ o.name }}</span>@if (o.siret) { <span class="cell-sub mono">{{ o.siret }}</span> }</div></div></td>
                    <td><span [class]="'badge plain ' + (o.type === 'buyer' ? 'tone-info' : 'tone-accent')">{{ o.type === 'buyer' ? 'Acheteur' : 'Fournisseur' }}</span></td>
                    <td>{{ o.city ?? '—' }}</td>
                    <td>@if (o.averageRating) { <app-stars [value]="o.averageRating" [size]="12" /> } @else { <span class="subtle">—</span> }</td>
                    <td class="subtle">{{ o.createdAt | frDate }}</td>
                    <td><span [class]="'badge ' + (o.active ? 'tone-success' : 'tone-danger')">{{ o.active ? 'Active' : 'Suspendue' }}</span></td>
                    <td class="num"><button class="btn btn-ghost btn-sm" (click)="toggle(o)"><app-icon [name]="o.active ? 'lock' : 'check'" [size]="14" />{{ o.active ? 'Suspendre' : 'Réactiver' }}</button></td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        <div class="data-foot"><app-pagination [page]="page()" [total]="total()" (pageChange)="reload($event)" /></div>
      }
      </section>
    </div>

    @if (creating()) {
      <div class="modal-backdrop" (click)="creating.set(false)">
        <form class="modal" [formGroup]="form" (ngSubmit)="create()" (click)="$event.stopPropagation()" role="dialog" aria-modal="true" aria-labelledby="org-title">
          <div class="modal-head">
            <div class="modal-icon"><app-icon name="building" [size]="18" /></div>
            <div><h3 id="org-title">Nouvelle organisation</h3><p>Les clients acheteurs sont créés par l'administration ; les fournisseurs peuvent aussi s'inscrire seuls.</p></div>
          </div>
          <label class="field"><span>Nom</span><input formControlName="name" autofocus /></label>
          <div class="grid-2">
            <label class="field"><span>Type</span><select formControlName="type"><option value="buyer">Client acheteur</option><option value="supplier">Fournisseur</option></select></label>
            <label class="field"><span>Ville</span><input formControlName="city" /></label>
            <label class="field"><span>SIRET</span><input formControlName="siret" maxlength="14" inputmode="numeric" /></label>
            <label class="field"><span>Secteur</span><input formControlName="sector" /></label>
          </div>
          <div class="modal-actions">
            <button type="button" class="btn btn-ghost" (click)="creating.set(false)">Annuler</button>
            <button class="btn btn-primary" [disabled]="form.invalid"><app-icon name="plus" />Créer</button>
          </div>
        </form>
      </div>
    }
  `,
})
export class AdminOrganizationsComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly toast = inject(ToastService);
  readonly items = signal<Organization[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly loading = signal(true);
  readonly creating = signal(false);
  readonly types = [
    { value: '', label: 'Toutes' },
    { value: 'buyer', label: 'Acheteurs' },
    { value: 'supplier', label: 'Fournisseurs' },
  ];
  readonly search$ = new Subject<void>();
  search = '';
  type = '';
  readonly form = inject(FormBuilder).nonNullable.group({
    name: ['', Validators.required],
    type: ['buyer', Validators.required],
    city: [''],
    siret: ['', Validators.pattern(/^\d{14}$/)],
    sector: [''],
  });

  constructor() {
    this.search$.pipe(debounceTime(300)).subscribe(() => this.reload(1));
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.creating.set(false);
  }

  ngOnInit(): void {
    this.reload(1);
  }

  reload(page: number): void {
    this.page.set(page);
    this.loading.set(true);
    this.api.list<Organization>('organizations', { page, name: this.search, type: this.type }).subscribe((c) => {
      this.items.set(c.member);
      this.total.set(c.totalItems);
      this.loading.set(false);
    });
  }

  toggle(o: Organization): void {
    this.api.patch<Organization>(`organizations/${o.id}`, { active: !o.active }).subscribe({
      next: () => {
        this.toast.success(o.active ? `${o.name} suspendue : ses utilisateurs ne peuvent plus se connecter.` : `${o.name} réactivée.`);
        this.reload(this.page());
      },
      error: (e) => this.toast.error(apiErrorMessage(e)),
    });
  }

  create(): void {
    const v = this.form.getRawValue();
    this.api.post<Organization>('organizations', { ...v, siret: v.siret || null }).subscribe({
      next: (o) => {
        this.creating.set(false);
        this.form.reset({ type: 'buyer' });
        this.toast.success(`${o.name} créée.`);
        this.reload(1);
      },
      error: (e) => this.toast.error(apiErrorMessage(e)),
    });
  }
}
