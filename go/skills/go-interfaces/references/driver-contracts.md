# Driver Contracts

**Where config picks one of several drivers for a job, a neutral package declares the contract each driver implements, each driver is a package of its own, and the code that uses a driver still declares the slice it calls.**

Contents: when this applies · the contract · a driver · choosing in main · the consumers' slices · an outside service as the domain's words · checklist.

## When this applies

Rule 1 has the consumer declare its interface, and for one adapter that's all it needs. A job with several drivers, picked by a config file and with more to come (payment providers, shipping carriers, storage backends), has a second question: what must a new driver do to be one? That answer is a contract, and it lives where every driver can read it and none owns it.

## The contract

```go
// Package carrier is what a shipping carrier does for the shop. Each driver is a
// subpackage; main picks one by the config's carrier.
package carrier

// Carrier books and tracks parcels with one shipping company.
type Carrier interface {
    Book(ctx context.Context, p Parcel) (Booking, error)
    Track(ctx context.Context, id BookingID) (Status, error)
}

// ErrNoService is returned, wrapped, when the carrier doesn't deliver to the address.
var ErrNoService = errors.New("no service to that address")
```

- **Neutral:** it imports no driver and no consumer. Its types (`Parcel`, `Booking`, `Status`) and sentinels are the contract's words, which every driver translates into.
- **Exported,** since `main` and every driver name it.
- **Its doc says what each method promises,** an empty result, an order, a failure's sentinel, since a driver written later reads only this.

## A driver

```
internal/carrier/          the contract
internal/carrier/parcelco/ a carrier's HTTP API
internal/carrier/memory/   a stand-in for local runs
```

Each driver returns its concrete type, and `main` proves the fit when it assigns one to a `carrier.Carrier`. A driver is tested against an `httptest` server, or a fake runner for a binary, as any adapter is ([services.md](services.md)).

## Choosing in main

The one `switch`, as [services.md](services.md#choosing-in-main) has it, returning `carrier.Carrier`. A config naming no driver the program has stops it at startup, naming the choices.

## The consumers' slices

The contract doesn't replace rule 1. Code that uses a carrier declares the one or two methods it calls, and the driver `main` built fills it:

```go
// package order

// booker is the one thing Dispatch asks of a carrier.
type booker interface {
    Book(ctx context.Context, p carrier.Parcel) (carrier.Booking, error)
}
```

Its tests fake `booker` in five lines, never the whole contract.

## An outside service as the domain's words

Rule 4 keeps a vendor's names out of the domain. Where an outside service's model is the program's subject, it's the other way round: a deploy tool built on a code host is about its commits, pull requests and workflow runs, and inventing a domain type for each would copy the host's model with nothing to swap it for.

- **The adapter's types and sentinels may be the domain's vocabulary:** a domain package imports the adapter for `Commit`, `PullRequest` and `ErrUnknownRef`.
- **The adapter is still yours.** It's your package, its types are your structs, mapped from the wire at its edge, and no wire struct, raw response or vendor error type leaves it.
- **The domain still reads through interfaces it declares,** so its tests fake those, and only `main` hands it the real client.
- **The cost:** the domain can't compile without the adapter, and the import runs from the domain to it. Accept it only where the service is the subject, not one supplier among several.

## Checklist

- [ ] A neutral package declares the contract, its types and its sentinels, and imports no driver.
- [ ] Each driver is a package of its own, returning its concrete type.
- [ ] One `switch` in `main` picks the driver; an unknown name stops the program.
- [ ] Each consumer still declares the slice it calls.
- [ ] An adapter's types serve as the domain's words only where its service is the program's subject, and never its wire structs.
