import { Component, computed, forwardRef, input, signal } from '@angular/core';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';
import { IconComponent } from './icon.component';

const LEVELS = ['Très faible', 'Faible', 'Moyen', 'Bon', 'Excellent'] as const;

/**
 * Password field with a visibility toggle, a Caps Lock warning and an optional strength meter.
 * The meter is guidance only: the API enforces the real policy (length, strength, breach check).
 */
@Component({
  selector: 'app-password-input',
  imports: [IconComponent],
  providers: [{ provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => PasswordInputComponent), multi: true }],
  host: { class: 'password-input' },
  template: `
    <div class="control-icon" [class.invalid]="invalid()">
      <app-icon name="key" class="control-lead" />
      <input #field [type]="visible() ? 'text' : 'password'" [value]="value()" [attr.id]="inputId()"
             [attr.autocomplete]="autocomplete()" [attr.aria-invalid]="invalid() || null" [attr.aria-describedby]="describedBy()"
             [placeholder]="placeholder()" [disabled]="disabled()"
             (input)="set(field.value)" (blur)="onTouched(); capsLock.set(false)" (keydown)="checkCaps($event)" (keyup)="checkCaps($event)" />
      <button type="button" class="control-trail" (click)="toggle(field)" [attr.aria-pressed]="visible()"
              [attr.aria-label]="visible() ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" [title]="visible() ? 'Masquer' : 'Afficher'">
        <app-icon [name]="visible() ? 'eye-off' : 'eye'" />
      </button>
    </div>
    @if (capsLock()) {
      <span class="field-note warn"><app-icon name="alert" [size]="13" />Verrouillage majuscules activé</span>
    }
    @if (showStrength() && value()) {
      <div class="strength" [attr.data-level]="score()" aria-live="polite">
        <div class="strength-bars" aria-hidden="true">@for (i of [0, 1, 2, 3]; track i) { <span [class.on]="i < score()"></span> }</div>
        <span class="strength-label">Robustesse : <b>{{ level() }}</b></span>
      </div>
    }
  `,
})
export class PasswordInputComponent implements ControlValueAccessor {
  readonly placeholder = input('');
  readonly autocomplete = input('current-password');
  readonly inputId = input<string | null>(null);
  readonly describedBy = input<string | null>(null);
  readonly invalid = input(false);
  readonly showStrength = input(false);

  readonly value = signal('');
  readonly visible = signal(false);
  readonly capsLock = signal(false);
  readonly disabled = signal(false);

  /** 0..4, from length and character variety. */
  readonly score = computed(() => {
    const v = this.value();
    if (!v) return 0;
    const classes = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter((r) => r.test(v)).length;
    let s = 0;
    if (v.length >= 12) s++;
    if (v.length >= 16) s++;
    if (classes >= 3) s++;
    if (classes === 4) s++;
    if (/^(.)\1+$/.test(v) || /password|motdepasse|azerty|qwerty|123456/i.test(v)) s = Math.min(s, 1);
    return s;
  });
  readonly level = computed(() => LEVELS[this.score()]);

  private onChange: (v: string) => void = () => {};
  onTouched: () => void = () => {};

  writeValue(v: string | null): void {
    this.value.set(v ?? '');
  }
  registerOnChange(fn: (v: string) => void): void {
    this.onChange = fn;
  }
  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }
  setDisabledState(disabled: boolean): void {
    this.disabled.set(disabled);
  }

  set(v: string): void {
    this.value.set(v);
    this.onChange(v);
  }

  toggle(field: HTMLInputElement): void {
    this.visible.update((v) => !v);
    field.focus();
  }

  checkCaps(event: KeyboardEvent): void {
    if (typeof event.getModifierState === 'function') this.capsLock.set(event.getModifierState('CapsLock'));
  }
}
