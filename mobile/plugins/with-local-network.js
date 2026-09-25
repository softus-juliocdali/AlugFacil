const { withAndroidManifest, withDangerousMod } = require('@expo/config-plugins');
const fs = require('node:fs/promises');
const path = require('node:path');

module.exports = function withLocalNetwork(config, { host }) {
  config = withAndroidManifest(config, (mod) => {
    const application = mod.modResults.manifest.application[0];
    application.$['android:usesCleartextTraffic'] = 'false';
    if (host) application.$['android:networkSecurityConfig'] = '@xml/alugfacil_network_security';
    else delete application.$['android:networkSecurityConfig'];
    return mod;
  });
  return withDangerousMod(config, ['android', async (mod) => {
    const directory = path.join(mod.modRequest.platformProjectRoot, 'app/src/main/res/xml');
    const file = path.join(directory, 'alugfacil_network_security.xml');
    if (host) {
      if (host !== '192.168.15.6') throw new Error('Host LAN não autorizado.');
      await fs.mkdir(directory, { recursive: true });
      await fs.writeFile(file, `<?xml version="1.0" encoding="utf-8"?>
<network-security-config>
  <base-config cleartextTrafficPermitted="false" />
  <domain-config cleartextTrafficPermitted="true">
    <domain includeSubdomains="false">${host}</domain>
  </domain-config>
</network-security-config>
`);
    } else {
      await fs.rm(file, { force: true });
    }
    return mod;
  }]);
};
