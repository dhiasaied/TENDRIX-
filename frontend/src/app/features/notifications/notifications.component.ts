import { HttpClient } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { AppNotification } from '../../core/models';
import { IconComponent, IconName } from '../../shared/icon.component';
import { AgoPipe, EmptyStateComponent, PaginationComponent, SkeletonComponent } from '../../shared/ui';

/** Icon + tone per notification family (prefix of the type, e.g. "invoice.paid"). */
const KIND: Record<string, [IconName, string]> = {
  tender: ['tender', 'tone-info'],
  proposal: ['proposal', 'tone-accent'],
  contract: ['contract', 'tone-success'],
  invoice: ['invoice', 'tone-warning'],
  evaluation: ['star', 'tone-warning'],
};

@Component({
  selector: 'app-notifications',
  imports: [AgoPipe, EmptyStateComponent, SkeletonComponent, PaginationComponent, IconComponent],
  template: `
    <div class="page">
      <header class="page-head">
        <div><h1>Notifications</h1><p class="lead">Les événements de vos appels d'offres, contrats et factures.</p></div>
        <div class="head-actions">
          <div class="segmented" role="radiogroup" aria-label="Filtrer">
            <button role="radio" [attr.aria-checked]="!unreadOnly()" [class.on]="!unreadOnly()" (click)="setFilter(false)">Toutes</button>
            <button role="radio" [attr.aria-checked]="unreadOnly()" [class.on]="unreadOnly()" (click)="setFilter(true)">Non lues</button>
          </div>
          <button class="btn btn-secondary" (click)="readAll()"><app-icon name="check" />Tout marquer comme lu</button>
        </div>
      </header>
      @if (loading()) {
        <app-skeleton />
      } @else if (!items().length) {
        <app-empty icon="bell" title="Vous êtes à jour" [hint]="unreadOnly() ? 'Aucune notification non lue.' : 'Aucune notification pour le moment.'" />
      } @else {
        <ul class="notif-list card flush">
          @for (n of items(); track n.id) {
            <li [class.unread]="!n.read" (click)="open(n)" tabindex="0" (keydown.enter)="open(n)">
              <span [class]="'notif-icon ' + tone(n.type)"><app-icon [name]="icon(n.type)" /></span>
              <div class="grow">
                <b>{{ n.title }}</b>
                <p>{{ n.message }}</p>
              </div>
              <time [attr.datetime]="n.createdAt">{{ n.createdAt | ago }}</time>
              @if (!n.read) { <span class="unread-dot" aria-label="Non lue"></span> }
            </li>
          }
        </ul>
        <app-pagination [page]="page()" [total]="total()" (pageChange)="load($event)" />
      }
    </div>
  `,
})
export class NotificationsComponent implements OnInit {
  private readonly api = inject(ApiService);
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  readonly items = signal<AppNotification[]>([]);
  readonly total = signal(0);
  readonly page = signal(1);
  readonly loading = signal(true);
  readonly unreadOnly = signal(false);

  ngOnInit(): void {
    this.load(1);
  }

  icon(type: string): IconName {
    return KIND[type.split('.')[0]]?.[0] ?? 'bell';
  }

  tone(type: string): string {
    return KIND[type.split('.')[0]]?.[1] ?? '';
  }

  setFilter(unread: boolean): void {
    this.unreadOnly.set(unread);
    this.load(1);
  }

  load(page: number): void {
    this.page.set(page);
    this.loading.set(true);
    this.api.list<AppNotification>('notifications', { page, 'exists[readAt]': this.unreadOnly() ? false : null }).subscribe((c) => {
      this.items.set(c.member);
      this.total.set(c.totalItems);
      this.loading.set(false);
    });
  }

  open(n: AppNotification): void {
    if (!n.read) this.api.patch(`notifications/${n.id}`, { read: true }).subscribe();
    if (n.link) this.router.navigateByUrl(n.link);
    else this.items.update((list) => list.map((i) => (i.id === n.id ? { ...i, read: true } : i)));
  }

  readAll(): void {
    this.http.post('/api/me/notifications/read-all', {}).subscribe(() => this.load(this.page()));
  }
}
