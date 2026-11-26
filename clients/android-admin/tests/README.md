# اختبارات تطبيق الإدارة

## `jvm/`: نواة الشبكة والرفع على خادم حي

تُترجم ملفات `Api` و`Json` و`Fmt` و`Session` و`SecureStore` و`Transfers` و`BuildInfo` نفسها، المستخدمة في التطبيق. تُستبدل فقط أصناف Android القليلة التي تحتاجها ببدائل صغيرة للاختبار في `fakes/`. بعد الترجمة يُشغَّل `CoreTest` ضد الخادم.

```bash
cd tests/jvm
mkdir -p lib out
curl -sSLo lib/json.jar https://repo1.maven.org/maven2/org/json/json/20240303/json-20240303.jar
A=../../java
javac --release 8 -encoding UTF-8 -cp lib/json.jar -d out $(find fakes -name "*.java") \
  $A/Api.java $A/Json.java $A/Fmt.java $A/Session.java $A/SecureStore.java $A/Transfers.java $A/BuildInfo.java \
  src/com/upsl/admin/CoreTest.java
java -Djava.awt.headless=true -cp out:lib/json.jar com.upsl.admin.CoreTest https://your-site.example admin 'كلمة-المرور'
```

يُنشئ الاختبار لعبتين مؤقتتين وملفات ونسخة احتياطية، ثم يحذفها كلها. ويخفض إعداد «تجميع مباشر حتى» مؤقتاً إلى 16MB ثم يعيده إلى 2048. **شغّله على خادم تجريبي** وليس على الموقع الحقيقي.

النتائج الأخيرة في `core-test-nginx.log` و`core-test-apache.log`: ‏37 من 37 على كل خادم.

## `robolectric/`: الشاشات الـ27 بإطار Android الحقيقي على JVM

يشغّل `ScreensTest` كل الشاشات بالترتيب ضد الخادم:

- الدخول، وتحذير http، وكلمة المرور الخاطئة.
- القوائم، والتحديد الجماعي، وتبويبات اللعبة، والنوافذ.
- التحقق في النماذج، والرفع عبر مستند `content://`.
- إخفاء الأقسام عن دور «مشرف»، ورفض الخادم بـ 403.

ويحفظ لقطة لكل حالة.

خطوات التشغيل:

1. ابنِ المشروع محلياً:
   ```bash
   SKETCHWARE_PRO_SRC=/path/to/Sketchware-Pro python3 tools/build_local.py build/local
   ```
   يتطلب هذا نسخة من مصدر Sketchware Pro لإعادة توليد أصنافه المساعدة؛ لا تُضمَّن هذه الأصناف هنا.
2. نزّل الاعتماديات:
   ```bash
   mvn dependency:copy-dependencies -DoutputDirectory=lib
   mvn -f pom-android-all.xml dependency:copy-dependencies -DoutputDirectory=aa
   ```
   تحتاج `aa/` إلى ملفي android-all للإصدارين 14 و15: عدّل الإصدار في `pom-android-all.xml` ونفّذ الأمر مرة لكل إصدار. ثم حوّل ملفات `.aar` في `lib/` إلى `.jar`: استخرج `classes.jar` من كل واحد.
3. أنشئ الملف `com/android/tools/test_config.properties` في مسار الأصناف، وفيه:
   - `android_resource_apk`: يشير إلى `build/local/base.apk`.
   - `android_merged_assets`: يشير إلى `build/local/assets`.
   - `android_merged_manifest`: يشير إلى نسخة من `build/local/AndroidManifest.xml` أُضيف إليها السطر `<uses-sdk android:minSdkVersion="21" android:targetSdkVersion="34"/>`.
   - `android_custom_package=com.upsl.admin`.
4. ترجم الأصناف:
   - المصادر: `build/local/gen-src` و`build/local/r` و`java/` و`ScreensTest.java`.
   - مسار الأصناف عند الترجمة: `lib/*.jar` ثم `android.jar` الإصدار 34.
5. شغّل الاختبار:
   ```bash
   java --add-opens java.base/java.io=ALL-UNNAMED \
     -Drobolectric.offline=true -Drobolectric.dependency.dir=aa \
     -Dupsl.server=https://your-site.example -Dupsl.shots=shots \
     -cp out:cfg:lib/*:android.jar org.junit.runner.JUnitCore com.upsl.admin.ScreensTest
   ```

نتيجة آخر تشغيل في `last-run.txt`: ‏20 من 20 فحصاً، بلا استثناءات غير معالجة. اللقطات في `../screenshots/`.

> Robolectric ليس جهازاً: لا يختبر إشعار خدمة الرفع على النظام، ولا منتقي الملفات الحقيقي، ولا Keystore الجهاز.
