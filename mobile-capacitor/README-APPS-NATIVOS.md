# Apps Nativos iOS & Android — AcheIAki (Capacitor)

Este diretório contém o projeto Capacitor pronto para empacotar a plataforma **AcheIAki** (consumidor + entregador + notificações push nativas) para publicação na **Google Play Store** e **Apple App Store**.

## Passo a Passo para Gerar os Binários

1. Instale as dependências:
   ```bash
   cd mobile-capacitor
   npm install
   npx cap add android
   npx cap add ios
   npx cap sync
   ```
2. **Android (`android/app/src/main/AndroidManifest.xml`)**:
   Adicione as permissões para rastreamento contínuo do entregador mesmo com a tela bloqueada:
   ```xml
   <uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION" />
   <uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />
   <uses-permission android:name="android.permission.ACCESS_BACKGROUND_LOCATION" />
   <uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
   <uses-permission android:name="android.permission.FOREGROUND_SERVICE_LOCATION" />
   <uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
   ```
   E adicione o arquivo `google-services.json` do Firebase em `android/app/` para receber Push FCM.
3. **iOS (`ios/App/App/Info.plist`)**:
   Adicione as chaves de localização contínua e push remoto:
   - `NSLocationWhenInUseUsageDescription`
   - `NSLocationAlwaysAndWhenInUseUsageDescription`
   - `UIBackgroundModes`: `location`, `remote-notification`
4. A ponte JavaScript em `js/native-bridge.js` detecta o Capacitor automaticamente e ativa `@capacitor-community/background-geolocation` na tela `motoboy.html` + `PushNotifications` nativo via `push_api.php`.
