import { HttpClient } from '@angular/common/http';
import { Component, DestroyRef, HostListener, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { filter, interval, startWith, switchMap } from 'rxjs';
import { AuthService } from '../core/auth.service';
import { ROLES } from '../core/labels';
import { Role } from '../core/models';
import { ThemeChoice, ThemeService } from '../core/theme.service';
import { IconComponent, IconName } from '../shared/icon.component';
import { InitialsPipe } from '../shared/ui';
import { CommandPaletteComponent } from './command-palette.component';

interface NavItem {
  label: string;
  path: string;
  icon: IconName;
  roles?: Role[];
}

@Component({
  selector: 'app-shell',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, IconComponent, InitialsPipe, CommandPaletteComponent],
  template: `
    <div class="app" [class.nav-open]="navOpen()">
      <aside class="sidebar" aria-label="Navigation principale">
        <a routerLink="/dashboard" class="brand" (click)="navOpen.set(false)">
          <span class="brand-mark"><app-icon name="layers" [size]="16" [stroke]="2" /></span>
          <span class="brand-name">TEND<b>RIX</b></span>
        </a>
        <nav class="sidebar-scroll">
          @for (group of nav(); track group.title) {
            @if (group.items.length) {
              <div class="nav-section">
                <p class="nav-title">{{ group.title }}</p>
                @for (item of group.items; track item.path) {
                  <a class="nav-link" [routerLink]="item.path" routerLinkActive="active" (click)="navOpen.set(false)">
                    <app-icon [name]="item.icon" [size]="18" />{{ item.label }}
                  </a>
                }
              </div>
            }
          }
        </nav>
        <div class="workspace">
          <span class="workspace-mark"><app-icon [name]="auth.isSupplier() ? 'briefcase' : auth.isAdmin() ? 'shield' : 'building'" [size]="16" /></span>
          <span class="workspace-meta">
            <b>{{ auth.organization()?.name }}</b>
            <small>{{ spaceLabel() }}</small>
          </span>
        </div>
      </aside>

      <div class="main">
        <header class="topbar">
          <button class="icon-btn menu-btn" (click)="navOpen.set(!navOpen())" aria-label="Ouvrir le menu"><app-icon name="menu" [size]="18" /></button>
          <button class="search-trigger" (click)="paletteOpen.set(true)" aria-label="Rechercher (Ctrl+K)">
            <app-icon name="search" />
            <span>Rechercher ou aller à…</span>
            <span class="kbd">{{ isMac ? '⌘' : 'Ctrl' }} K</span>
          </button>
          <div class="topbar-right">
            <a routerLink="/notifications" class="icon-btn" [attr.aria-label]="'Notifications' + (unread() ? ' (' + unread() + ' non lues)' : '')">
              <app-icon name="bell" [size]="18" />
              @if (unread() > 0) { <span class="badge-count">{{ unread() > 9 ? '9+' : unread() }}</span> }
            </a>
            <span class="divider-v"></span>
            <div class="user-menu">
              <button class="user-btn" (click)="menuOpen.set(!menuOpen()); $event.stopPropagation()" [attr.aria-expanded]="menuOpen()" aria-haspopup="menu">
                <span class="avatar">{{ auth.displayName() | initials }}</span>
                <span class="user-meta"><b>{{ auth.displayName() }}</b><small>{{ roleLabel() }}</small></span>
                <app-icon name="chevron-down" [size]="14" class="subtle" />
              </button>
              @if (menuOpen()) {
                <div class="menu" role="menu" (click)="$event.stopPropagation()">
                  <div class="menu-head"><b>{{ auth.displayName() }}</b><small>{{ auth.session()?.email }}</small></div>
                  <a class="menu-item" routerLink="/profile" role="menuitem" (click)="menuOpen.set(false)"><app-icon name="user" />Mon profil</a>
                  @if (auth.isSupplier() || auth.isBuyer()) {
                    <a class="menu-item" routerLink="/organization" role="menuitem" (click)="menuOpen.set(false)"><app-icon name="building" />Mon entreprise</a>
                  }
                  <div class="menu-sep"></div>
                  <p class="menu-label">Thème</p>
                  <div class="theme-switch" role="radiogroup" aria-label="Thème">
                    @for (t of themes; track t.value) {
                      <button role="radio" [attr.aria-checked]="theme.choice() === t.value" [class.on]="theme.choice() === t.value" (click)="theme.set(t.value)" [attr.aria-label]="t.label" [title]="t.label">
                        <app-icon [name]="t.icon" />
                      </button>
                    }
                  </div>
                  <div class="menu-sep"></div>
                  <button class="menu-item" role="menuitem" (click)="auth.logout()"><app-icon name="logout" />Se déconnecter</button>
                </div>
              }
            </div>
          </div>
        </header>
        <main class="content" id="main">
          <router-outlet />
        </main>
      </div>
      <div class="nav-overlay" (click)="navOpen.set(false)"></div>
    </div>

    @if (paletteOpen()) {
      <app-command-palette [navigation]="flatNav()" (close)="paletteOpen.set(false)" />
    }
  `,
})
export class ShellComponent implements OnInit {
  readonly auth = inject(AuthService);
  readonly theme = inject(ThemeService);
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);

  readonly isMac = /Mac|iPhone|iPad/.test(navigator.platform);
  readonly unread = signal(0);
  readonly navOpen = signal(false);
  readonly menuOpen = signal(false);
  readonly paletteOpen = signal(false);
  readonly themes: { value: ThemeChoice; label: string; icon: IconName }[] = [
    { value: 'light', label: 'Clair', icon: 'sun' },
    { value: 'dark', label: 'Sombre', icon: 'moon' },
    { value: 'system', label: 'Système', icon: 'monitor' },
  ];

  readonly roleLabel = computed(() => this.auth.roles().filter((r) => r !== 'ROLE_USER').map((r) => ROLES[r]).join(', '));
  readonly spaceLabel = computed(() =>
    this.auth.isAdmin() ? 'Administration plateforme' : this.auth.isSupplier() ? 'Espace fournisseur' : 'Espace acheteur',
  );

  readonly nav = computed(() => {
    const supplier = this.auth.isSupplier();
    const groups: { title: string; items: NavItem[] }[] = [
      {
        title: 'Pilotage',
        items: [
          { label: 'Tableau de bord', path: '/dashboard', icon: 'dashboard' },
          { label: supplier ? 'Opportunités' : 'Appels d\'offres', path: '/tenders', icon: supplier ? 'opportunity' : 'tender', roles: ['ROLE_BUYER', 'ROLE_REQUESTER', 'ROLE_SUPPLIER'] },
          { label: 'Propositions', path: '/proposals', icon: 'proposal', roles: ['ROLE_BUYER', 'ROLE_REQUESTER', 'ROLE_SUPPLIER'] },
        ],
      },
      {
        title: 'Exécution',
        items: [
          { label: 'Contrats', path: '/contracts', icon: 'contract', roles: ['ROLE_BUYER', 'ROLE_REQUESTER', 'ROLE_SUPPLIER'] },
          { label: 'Factures', path: '/invoices', icon: 'invoice', roles: ['ROLE_BUYER', 'ROLE_SUPPLIER'] },
        ],
      },
      {
        title: 'Analyse',
        items: [
          { label: supplier ? 'Ma performance' : 'Performance fournisseurs', path: '/suppliers', icon: 'analytics' },
          { label: 'Annuaire fournisseurs', path: '/directory', icon: 'building', roles: ['ROLE_BUYER', 'ROLE_REQUESTER'] },
        ],
      },
      {
        title: 'Administration',
        items: [
          { label: 'Organisations', path: '/admin/organizations', icon: 'building', roles: ['ROLE_ADMIN'] },
          { label: 'Utilisateurs', path: '/admin/users', icon: 'user-cog', roles: ['ROLE_ADMIN'] },
          { label: 'Journal d\'audit', path: '/admin/audit', icon: 'history', roles: ['ROLE_ADMIN'] },
        ],
      },
    ];
    return groups.map((g) => ({ ...g, items: g.items.filter((i) => !i.roles || this.auth.hasAnyRole(i.roles)) }));
  });
  readonly flatNav = computed(() => [
    ...this.nav().flatMap((g) => g.items),
    { label: 'Notifications', path: '/notifications', icon: 'bell' as IconName },
    { label: 'Mon profil', path: '/profile', icon: 'user' as IconName },
  ]);

  @HostListener('document:keydown', ['$event'])
  onKeydown(event: KeyboardEvent): void {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      this.paletteOpen.set(!this.paletteOpen());
    }
  }

  @HostListener('document:click')
  onDocumentClick(): void {
    this.menuOpen.set(false);
  }

  ngOnInit(): void {
    interval(30_000)
      .pipe(
        startWith(0),
        switchMap(() => this.http.get<{ count: number }>('/api/me/notifications/unread-count')),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({ next: (r) => this.unread.set(r.count), error: () => undefined });

    this.router.events
      .pipe(filter((e) => e instanceof NavigationEnd), takeUntilDestroyed(this.destroyRef))
      .subscribe(() => {
        this.menuOpen.set(false);
        this.http.get<{ count: number }>('/api/me/notifications/unread-count').subscribe({ next: (r) => this.unread.set(r.count), error: () => undefined });
      });
  }
}
