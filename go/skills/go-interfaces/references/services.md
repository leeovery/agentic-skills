# Outside Services

**Your interface in your domain's words, one adapter that knows the vendor, and a fake of your interface for everyone else.**

Contents: the shape · the interface · the adapter · testing the adapter · the fake for callers · a stand-in for local runs · choosing in main · SDKs.

## The shape

```
internal/order/      Refunder, declared where Cancel calls it
internal/gateway/    Client, the HTTP adapter; Memory, the stand-in for local runs
cmd/shop/main.go     the switch that picks one
```

`gateway` imports `order` for its types and errors; `order` never imports `gateway`. The domain compiles, and its tests run, without the adapter.

## The interface

Domain types in and out: `PaymentID`, `Cents`, your own errors.

```go
// Refunder is exported because main names it when it picks one.
type Refunder interface {
	Refund(ctx context.Context, payment PaymentID, amount Cents) error
}
```

No vendor request struct, `*http.Response` or vendor error type appears in it. Once the vendor's names are in the signature, the interface is the SDK by another name, and replacing the vendor rewrites every caller.

## The adapter

```go
// internal/gateway: the payment gateway's HTTP API, in the order package's terms.

// Client is the gateway's API over HTTP.
type Client struct {
	HTTP    *http.Client
	BaseURL string
	Timeout time.Duration // for each call, reading the answer included
}

// refundRequest is the wire shape; it never leaves this package.
type refundRequest struct {
	Payment string `json:"payment_id"`
	Amount  int64  `json:"amount_minor"`
}

// Refund refunds amount of payment. The gateway refunds a payment once, however
// often it's asked.
func (c *Client) Refund(ctx context.Context, payment order.PaymentID, amount order.Cents) error {
	ctx, cancel := context.WithTimeout(ctx, c.Timeout)
	defer cancel()
	body, err := json.Marshal(refundRequest{Payment: string(payment), Amount: int64(amount)})
	if err != nil {
		return fmt.Errorf("refund %s: %w", payment, err)
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.BaseURL+"/refunds", bytes.NewReader(body))
	if err != nil {
		return fmt.Errorf("refund %s: %w", payment, err)
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Idempotency-Key", "refund-"+string(payment))
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return fmt.Errorf("refund %s: %w", payment, err)
	}
	defer func() { _ = resp.Body.Close() }()
	switch resp.StatusCode {
	case http.StatusOK, http.StatusCreated:
		return nil
	case http.StatusNotFound:
		return fmt.Errorf("refund %s: %w", payment, order.ErrPaymentNotFound)
	}
	return fmt.Errorf("refund %s: gateway answered %s", payment, resp.Status)
}
```

- **`Client` is concrete,** never `order.Refunder`. Whoever holds it decides which interface it fills.
- **It holds an `*http.Client`, a base URL and a timeout,** each set by `main`, or by a test ([seams.md](seams.md)). `json` is `encoding/json/v2` ([Go 1.27](https://go.dev/doc/go1.27)).
- **The wire shape stays unexported,** and the mapping to it is the adapter's whole job ([go-types](../../go-types/SKILL.md)).
- **The vendor's not-found becomes the domain's sentinel;** every other failure is wrapped with the payment it was for ([go-errors' wrapping.md](../../go-errors/references/wrapping.md)). A failure worth retrying can come back as an error that answers `Retryable()` ([go-errors' matching.md](../../go-errors/references/matching.md)).
- **The refund carries an idempotency key from the payment,** so the gateway refunds once however often it's asked. `Cancel` refunds before its writes, and a retry after a failed write asks again ([go-actions' operations.md](../../go-actions/references/operations.md#writes)).
- **The request carries `ctx`,** so a cancelled caller cancels the request.
- **No decisions.** Whether an order may be refunded is `order`'s question. The adapter refunds.

## Testing the adapter

Against a real HTTP server in the test process. The test sees the request the vendor would see, and checks how each answer is translated:

```go
func newTestClient(t *testing.T, h http.HandlerFunc) *Client {
	t.Helper()
	srv := httptest.NewTestServer(t, h)
	hc := srv.Client() // sets srv.URL, so it comes first
	return &Client{HTTP: hc, BaseURL: srv.URL, Timeout: 5 * time.Second}
}

func TestRefundPostsThePaymentAndAmount(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		var got refundRequest
		if err := json.UnmarshalRead(r.Body, &got); err != nil {
			t.Errorf("decode refund body: %v", err)
		}
		want := refundRequest{Payment: "pay-1", Amount: 1250}
		if r.Method != http.MethodPost || r.URL.Path != "/refunds" || got != want {
			t.Errorf("request = %s %s %+v, want POST /refunds %+v", r.Method, r.URL.Path, got, want)
		}
		if key := r.Header.Get("Idempotency-Key"); key != "refund-pay-1" {
			t.Errorf("Idempotency-Key = %q, want refund-pay-1", key)
		}
		w.WriteHeader(http.StatusCreated)
	})

	if err := c.Refund(t.Context(), "pay-1", 1250); err != nil {
		t.Errorf("Refund(pay-1, 1250) = %v, want nil", err)
	}
}

func TestRefundReportsAnUnknownPayment(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusNotFound)
	})

	err := c.Refund(t.Context(), "pay-404", 1250)

	if !errors.Is(err, order.ErrPaymentNotFound) {
		t.Errorf("Refund(pay-404) = %v, want ErrPaymentNotFound", err)
	}
}
```

- **`httptest.NewTestServer`** ([Go 1.27](https://go.dev/doc/go1.27)) serves over an in-memory network, never a port, and closes when the test ends. Inside a `synctest` bubble, a test of the client's timeout or retry waits on the fake clock.
- The handler runs on the server's goroutine, so it reports with `t.Errorf`. `t.Fatal` there would stop the wrong goroutine.

## The fake for callers

Code that refunds is tested with the hand-written `fakeRefunder` in [go-testing's fakes-and-builders.md](../../go-testing/references/fakes-and-builders.md), and checks what it recorded:

```go
if want := []PaymentID{"pay-1"}; !slices.Equal(payments.refunded, want) {
	t.Errorf("refunded %v, want %v", payments.refunded, want)
}
```

Its tests never see HTTP, so a change in the gateway's API breaks `gateway`'s tests alone. A fake called from several goroutines guards its fields with a mutex; the race detector reports one that doesn't.

## A stand-in for local runs

Running the program without the vendor (on a laptop, in a demo) needs an implementation that's real code, chosen by config:

```go
// Memory accepts every refund and keeps it, for local runs without the gateway.
type Memory struct {
	mu       sync.Mutex
	refunded map[order.PaymentID]order.Cents
}

// Refund keeps one refund per payment, as the gateway does.
func (m *Memory) Refund(_ context.Context, payment order.PaymentID, amount order.Cents) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.refunded == nil {
		m.refunded = make(map[order.PaymentID]order.Cents)
	}
	m.refunded[payment] = amount
	return nil
}
```

It isn't a test double. A test's fake records exactly what the test asks about and fails when told to; the stand-in behaves plausibly and never fails.

## Choosing in main

```go
// newRefunder picks the implementation; it is the one place that knows there is more than one.
func newRefunder(cfg Config) (order.Refunder, error) {
	switch cfg.Payments {
	case "gateway":
		return &gateway.Client{HTTP: &http.Client{}, BaseURL: cfg.GatewayURL, Timeout: 10 * time.Second}, nil
	case "memory":
		return &gateway.Memory{}, nil
	}
	return nil, fmt.Errorf("payments %q: want gateway or memory", cfg.Payments)
}
```

An unknown value stops the program at startup, naming the choices, instead of running with a default nobody picked. No registry, factory or container: one `switch`, read top to bottom.

## SDKs

- **The adapter wraps the SDK's client.** The interface the domain sees is still yours, in your words.
- **Point the SDK at an `httptest` server** to test the adapter. Most SDKs take a base URL or an `*http.Client` as an option.
- **Never declare an interface over the SDK's client to fake it, and never fake its types.** The fake would encode your guess at the SDK's behaviour, and pass where the real SDK fails.
- **When the SDK takes neither a base URL nor a client,** keep the adapter thin enough that its check is a run against the vendor's sandbox, outside the unit tests.
