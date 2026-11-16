import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import {
  ArrowDown, ArrowLeft, ArrowRight, ArrowUp, ArrowUpRight, Award, Bell, BookOpen, Briefcase, Building2, Calendar, CalendarClock, ChartColumn,
  Check, ChevronDown, ChevronLeft, ChevronRight, CircleAlert, CircleCheck, CircleX, Clock, Coins, Command, CornerDownLeft,
  ChevronsUpDown, Ellipsis, Euro, Eye, EyeOff, FilePen, RotateCcw, SlidersHorizontal, FileSearch, FileText, Gauge, Globe, Handshake, History, Inbox, Info, KeyRound, LayoutDashboard,
  Layers, ListFilter, LoaderCircle, Lock, LogOut, LucideAngularModule, LucideIconData, Mail, MapPin, Menu, MessageSquare, Monitor,
  Moon, PenLine, Pencil, Phone, Plus, Printer, Receipt, Search, Send, ShieldCheck, Sparkles, Star, Sun, Target, Trash2,
  TrendingUp, TriangleAlert, User, UserCog, Users, Wallet, X,
} from 'lucide-angular';

/**
 * Single source of truth for the icon set: only icons registered here can be used, which keeps
 * the bundle small and the visual vocabulary consistent.
 */
export const ICONS = {
  dashboard: LayoutDashboard,
  tender: FileText,
  opportunity: FileSearch,
  proposal: Inbox,
  inbox: Inbox,
  contract: FilePen,
  invoice: Receipt,
  analytics: ChartColumn,
  building: Building2,
  users: Users,
  'user-cog': UserCog,
  user: User,
  history: History,
  bell: Bell,
  menu: Menu,
  search: Search,
  sun: Sun,
  moon: Moon,
  monitor: Monitor,
  logout: LogOut,
  'chevron-right': ChevronRight,
  'chevron-left': ChevronLeft,
  'chevron-down': ChevronDown,
  'arrow-left': ArrowLeft,
  'arrow-up': ArrowUp,
  'arrow-down': ArrowDown,
  'arrow-right': ArrowRight,
  'arrow-up-right': ArrowUpRight,
  plus: Plus,
  sparkles: Sparkles,
  euro: Euro,
  coins: Coins,
  wallet: Wallet,
  message: MessageSquare,
  star: Star,
  x: X,
  check: Check,
  'circle-check': CircleCheck,
  'circle-x': CircleX,
  alert: TriangleAlert,
  'circle-alert': CircleAlert,
  info: Info,
  calendar: Calendar,
  'calendar-clock': CalendarClock,
  clock: Clock,
  pin: MapPin,
  briefcase: Briefcase,
  award: Award,
  target: Target,
  gauge: Gauge,
  printer: Printer,
  pencil: Pencil,
  'pen-line': PenLine,
  trash: Trash2,
  send: Send,
  filter: ListFilter,
  trending: TrendingUp,
  lock: Lock,
  mail: Mail,
  key: KeyRound,
  command: Command,
  enter: CornerDownLeft,
  handshake: Handshake,
  layers: Layers,
  globe: Globe,
  phone: Phone,
  loader: LoaderCircle,
  book: BookOpen,
  more: Ellipsis,
  eye: Eye,
  'eye-off': EyeOff,
  reset: RotateCcw,
  sliders: SlidersHorizontal,
  sort: ChevronsUpDown,
  shield: ShieldCheck,
} satisfies Record<string, LucideIconData>;

export type IconName = keyof typeof ICONS;

/**
 * <app-icon name="tender" /> — 16px / 1.75 stroke by default so every icon shares one optical weight.
 * Decorative by default (aria-hidden); pass `label` when the icon carries meaning on its own.
 */
@Component({
  selector: 'app-icon',
  imports: [LucideAngularModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'icon', '[attr.role]': 'label() ? "img" : null', '[attr.aria-label]': 'label()', '[attr.aria-hidden]': 'label() ? null : "true"' },
  template: `<lucide-icon [img]="data()" [size]="size()" [strokeWidth]="stroke()" />`,
})
export class IconComponent {
  readonly name = input.required<IconName>();
  readonly size = input(16);
  readonly stroke = input(1.75);
  readonly label = input<string | null>(null);
  readonly data = computed(() => ICONS[this.name()]);
}
