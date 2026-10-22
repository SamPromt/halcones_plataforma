require('dotenv').config();

module.exports = {
  port: Number(process.env.PORT || 3001),
  jwtSecret: process.env.JWT_SECRET || 'halcones-dev-secret',
  appName: process.env.APP_NAME || 'Halcones Monolith',
};
