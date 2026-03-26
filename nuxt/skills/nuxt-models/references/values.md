# Value Objects

Value objects encapsulate primitive values with behavior and formatting.

## Castable Interface

Value objects implement `Castable` for model integration:

```typescript
import type { Castable } from '#layers/base/app/types'

class DateValue implements Castable {
  // Static cast method required
  static cast(value: string): DateValue {
    return new DateValue(value)
  }
}
```

---

## DateValue

The primary value object for date handling:

```typescript
// app/values/DateValue.ts
import type { Castable } from '#layers/base/app/types'

export default class DateValue implements Castable {
  iso8601: string

  constructor(iso8601: string) {
    this.iso8601 = iso8601
  }

  // Format using dayjs (auto-imported from layer)
  format(format: string = 'DD MMM YYYY'): string | undefined {
    if (!this.iso8601) return undefined
    return useDayjs()(this.iso8601).format(format)
  }

  // Convert to Date object
  toDate(): Date {
    return new Date(this.iso8601)
  }

  // Get relative time
  fromNow(): string | undefined {
    if (!this.iso8601) return undefined
    return useDayjs()(this.iso8601).fromNow()
  }

  // Check if date is in past
  isPast(): boolean {
    return new Date(this.iso8601) < new Date()
  }

  // Check if date is in future
  isFuture(): boolean {
    return new Date(this.iso8601) > new Date()
  }

  // Static cast method for Model casting system
  static cast(value: string): DateValue {
    return new DateValue(value)
  }
}
```

### Usage in Models

```typescript
import DateValue from '~/values/DateValue'

class Post extends Model {
  createdAt: DateValue
  updatedAt: DateValue
  publishAt?: DateValue

  public override casts(): Record<string, Castable> {
    return {
      createdAt: DateValue,
      updatedAt: DateValue,
      publishAt: DateValue,
    }
  }
}
```

### Usage in Templates

```vue
<template>
  <div>
    <!-- Formatted date -->
    <span>{{ post.createdAt.format('DD MMM YYYY') }}</span>

    <!-- Different formats -->
    <span>{{ post.createdAt.format('YYYY-MM-DD HH:mm') }}</span>

    <!-- Relative time -->
    <span>{{ post.createdAt.fromNow() }}</span>

    <!-- Conditional display -->
    <span v-if="post.publishAt?.isFuture()">
      Scheduled for {{ post.publishAt.format() }}
    </span>
  </div>
</template>
```

---

## Creating Custom Value Objects

### Money Value

```typescript
// app/values/MoneyValue.ts
import type { Castable } from '#layers/base/app/types'

interface MoneyData {
  amount: number
  currency: string
}

export default class MoneyValue implements Castable {
  amount: number
  currency: string

  constructor(data: MoneyData) {
    this.amount = data.amount
    this.currency = data.currency
  }

  format(): string {
    return new Intl.NumberFormat('en-GB', {
      style: 'currency',
      currency: this.currency,
    }).format(this.amount / 100)  // Assuming cents
  }

  add(other: MoneyValue): MoneyValue {
    if (this.currency !== other.currency) {
      throw new Error('Cannot add different currencies')
    }
    return new MoneyValue({
      amount: this.amount + other.amount,
      currency: this.currency,
    })
  }

  static cast(value: MoneyData): MoneyValue {
    return new MoneyValue(value)
  }
}
```

### Phone Value

```typescript
// app/values/PhoneValue.ts
import type { Castable } from '#layers/base/app/types'

export default class PhoneValue implements Castable {
  raw: string

  constructor(raw: string) {
    this.raw = raw
  }

  format(): string {
    // UK format: 07XXX XXX XXX
    const cleaned = this.raw.replace(/\D/g, '')
    if (cleaned.length === 11 && cleaned.startsWith('07')) {
      return `${cleaned.slice(0, 5)} ${cleaned.slice(5, 8)} ${cleaned.slice(8)}`
    }
    return this.raw
  }

  get international(): string {
    const cleaned = this.raw.replace(/\D/g, '')
    if (cleaned.startsWith('07')) {
      return '+44' + cleaned.slice(1)
    }
    return cleaned
  }

  static cast(value: string): PhoneValue {
    return new PhoneValue(value)
  }
}
```

---

## Value Object Patterns

### Immutability

Value objects should be immutable:

```typescript
class DateValue implements Castable {
  readonly iso8601: string  // readonly prevents mutation

  constructor(iso8601: string) {
    this.iso8601 = iso8601
  }

  // Return new instance instead of mutating
  addDays(days: number): DateValue {
    const date = useDayjs()(this.iso8601).add(days, 'day')
    return new DateValue(date.toISOString())
  }
}
```

### Equality

Compare by value, not reference:

```typescript
class MoneyValue implements Castable {
  equals(other: MoneyValue): boolean {
    return this.amount === other.amount && this.currency === other.currency
  }
}
```

### Null Safety

Handle null/undefined gracefully:

```typescript
class DateValue implements Castable {
  format(format: string = 'DD MMM YYYY'): string | undefined {
    if (!this.iso8601) return undefined  // Safe for null/undefined
    return useDayjs()(this.iso8601).format(format)
  }
}
```

---

## When to Use Value Objects

| Use Case | Example |
|----------|---------|
| Formatted display | `DateValue.format()` |
| Multiple representations | `PhoneValue.format()` vs `.international` |
| Domain behavior | `MoneyValue.add()` |
| Validation logic | `EmailValue.isValid()` |
| Unit conversion | `DistanceValue.toMiles()` |

### Don't Use Value Objects For

- Simple scalar values without behavior
- Values that don't need formatting
- One-off transformations (use utils)

---

## Directory Structure

```
app/
└── values/
    ├── DateValue.ts
    ├── MoneyValue.ts
    └── PhoneValue.ts
```

---

## Related Skills

- **[nuxt-models](../../nuxt-models/SKILL.md)** - Using values in models
- **[nuxt-enums](../../nuxt-enums/SKILL.md)** - Similar pattern for fixed values
