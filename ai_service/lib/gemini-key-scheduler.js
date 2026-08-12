/** In-memory lease scheduler: prefer a key that has no request in flight. */
export class GeminiKeyScheduler {
  #inFlight = new Map();

  rank(slotCount) {
    return Array.from({ length: Math.max(0, Number(slotCount) || 0) }, (_, index) => index)
      .sort((left, right) => (this.#inFlight.get(left) || 0) - (this.#inFlight.get(right) || 0) || left - right);
  }

  reserve(slotIndex) {
    this.#inFlight.set(slotIndex, (this.#inFlight.get(slotIndex) || 0) + 1);
    let released = false;
    return () => {
      if (released) return;
      released = true;
      const remaining = (this.#inFlight.get(slotIndex) || 1) - 1;
      if (remaining <= 0) this.#inFlight.delete(slotIndex);
      else this.#inFlight.set(slotIndex, remaining);
    };
  }
}
