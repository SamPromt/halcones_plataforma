function getSummary() {
  const cash = 7500;
  const transfer = 9000;
  const total = cash + transfer;
  const goal = 100000;
  const progress = Math.min((total / goal) * 100, 100);

  return {
    cash,
    transfer,
    total,
    goal,
    progress: Number(progress.toFixed(2)),
    remaining: Math.max(goal - total, 0),
    currency: 'COP',
    updatedAt: new Date().toISOString(),
  };
}

module.exports = { getSummary };
