const driverState = {
  id: 'driver-001',
  name: 'Julián V.',
  code: 'HLC-102',
  status: 'DISPONIBLE',
  available: true,
  vehicle: {
    plate: 'TZV-412',
    model: 'Renault Logan',
    type: 'Auto',
  },
  balance: {
    cash: 7500,
    transfer: 9000,
    total: 16500,
    meta: 100000,
  },
};

function getDriverStatus() {
  return {
    ...driverState,
    lastUpdated: new Date().toISOString(),
  };
}

function updateDriverStatus(status) {
  driverState.status = status;
  driverState.available = status === 'DISPONIBLE';
  return {
    status,
    available: driverState.available,
    updatedAt: new Date().toISOString(),
    message: `Conductor actualizado a ${status}`,
  };
}

module.exports = { getDriverStatus, updateDriverStatus };
