const rides = [
  {
    id: 'FUSA-9421',
    origin: 'Cra 6 #8-32, Centro',
    destination: 'Barrio Pekín, Carrera 1',
    price: 8000,
    distanceKm: 2.8,
    vehicleType: 'Auto',
    status: 'pending',
  },
  {
    id: 'FUSA-8832',
    origin: 'Terminal Transportes',
    destination: 'Balmoral, Av. Las Palmas',
    price: 7500,
    distanceKm: 1.5,
    vehicleType: 'Auto',
    status: 'pending',
  },
  {
    id: 'FUSA-1104',
    origin: 'Hospital San Rafael',
    destination: 'Coburgo, Calle 11',
    price: 6000,
    distanceKm: 1.2,
    vehicleType: 'Auto',
    status: 'pending',
  },
];

function getAvailableRides() {
  return rides;
}

function acceptRide(rideId) {
  return {
    accepted: true,
    rideId,
    driverId: 'driver-001',
    message: `Servicio ${rideId} aceptado correctamente`,
    status: 'accepted',
  };
}

function resolveRide(rideId, status) {
  return {
    rideId,
    status,
    resolvedAt: new Date().toISOString(),
    message: `Servicio ${rideId} marcado como ${status}`,
  };
}

module.exports = { getAvailableRides, acceptRide, resolveRide };
