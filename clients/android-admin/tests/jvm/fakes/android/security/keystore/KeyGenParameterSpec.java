package android.security.keystore;
public class KeyGenParameterSpec implements java.security.spec.AlgorithmParameterSpec {
    public static class Builder {
        public Builder(String alias, int purposes) { }
        public Builder setBlockModes(String... m) { return this; }
        public Builder setEncryptionPaddings(String... p) { return this; }
        public Builder setKeySize(int s) { return this; }
        public KeyGenParameterSpec build() { return new KeyGenParameterSpec(); }
    }
}
