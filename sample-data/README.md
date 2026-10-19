# Sample data (synthetic)

All names and registration numbers here are **made up** for local testing and demos.
Never put real student data in this repository.

| File | What it is |
|---|---|
| `students.csv` | 6 students (`UNI2026001`–`UNI2026006`). Import first. |
| `results.csv` | 4 first-year engineering subjects per student in each of two terms (S1: Mathematics I, Physics, Basic Electrical, Graphics; S2: Mathematics II, Chemistry, Mechanics, Programming). `2026-S1` is published by `local-dev/setup.sh`; `2026-S2` is left unpublished so you can see that students can't view it yet. `UNI2026006` deliberately fails `MA101` (Engineering Mathematics I). |
| `invalid-examples.csv` | Results file where every row except the first has a problem (unknown student, marks above max, bad term, missing subject name, non-numeric marks, duplicate line). Use it to see the import preview's error messages. |
